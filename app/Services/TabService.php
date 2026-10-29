<?php

namespace App\Services;

use App\Models\DiningTable;
use App\Models\Domain;
use App\Models\InventoryLocation;
use App\Models\KitchenTicket;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use App\Models\VoidLog;
use App\Support\ManagerApproval;
use App\Support\Quantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dine-in tabs. A tab is a pending sale marked is_tab: it belongs to the store, any cashier there
 * can add to it, and it is paid like any other sale. The till remembers which tab it is working on
 * (in the session), and while one is picked the usual cart (add, quantities, discounts, payment)
 * works on that tab instead of the cashier's own cart.
 */
class TabService
{
    private const SESSION_KEY = 'pos.tab.';

    /** The tab this till is working on, if it is still open at this store. */
    public function activeTab(Domain $domain, ?InventoryLocation $location): ?Sale
    {
        $id = session(self::SESSION_KEY.$domain->name_slug);
        // Dine-in off (coming soon, or not in the plan): the till works on its own cart; open tabs stay as they are.
        if (! $id || ! $domain->hasFeature('dine_in')) {
            return null;
        }

        $tab = Sale::query()->forDomain($domain->name_slug)->openTabs()
            ->when($location, fn ($q) => $q->where('location_id', $location->id))
            ->find($id);

        if (! $tab) {
            $this->forget($domain);
        }

        return $tab;
    }

    public function select(Domain $domain, Sale $tab): void
    {
        session([self::SESSION_KEY.$domain->name_slug => $tab->id]);
    }

    public function forget(Domain $domain): void
    {
        session()->forget(self::SESSION_KEY.$domain->name_slug);
    }

    /** A tab must be open, in this organization and at the store the till is in. */
    public function assertOpenAt(Sale $tab, Domain $domain, InventoryLocation $location): void
    {
        if ($tab->domain !== $domain->name_slug || ! $tab->is_tab || (int) $tab->location_id !== (int) $location->id) {
            abort(404);
        }
        if ($tab->payment_status !== 'pending') {
            throw ValidationException::withMessages(['tab' => ['This tab is already closed.']]);
        }
    }

    /** Open a tab on a table, or under a name (a counter or take-away guest). */
    public function open(Domain $domain, InventoryLocation $location, User $user, ?DiningTable $table, ?string $name, ?int $guests): Sale
    {
        if (! $table && ! filled($name)) {
            throw ValidationException::withMessages(['tab_name' => ['Pick a table or type a name for the tab.']]);
        }
        // Guests seated at a table waiting to be cleared: it's ready now.
        $table?->update(['needs_cleaning_at' => null]);

        return Sale::query()->create([
            'user_id' => $user->id,
            'opened_by' => $user->id,
            'location_id' => $location->id,
            'domain' => $domain->name_slug,
            'payment_status' => 'pending',
            'is_tab' => true,
            'dining_table_id' => $table?->id,
            'tab_name' => filled($name) ? trim($name) : null,
            'guests' => $guests,
            'invoice_number' => Str::random(10),
            'transaction_date' => now(),
        ]);
    }

    /** Guests moved to another table (or off a table, onto their name). */
    public function move(Sale $tab, ?DiningTable $table): Sale
    {
        if (! $table && ! filled($tab->tab_name)) {
            throw ValidationException::withMessages(['dining_table_id' => ['Give the tab a name before taking it off its table.']]);
        }
        $tab->update(['dining_table_id' => $table?->id]);

        return $tab->fresh();
    }

    /**
     * Two tabs become one (two tables that joined): the lines of $from go onto $into, with what
     * the kitchen already has, and $from closes.
     */
    public function merge(Sale $from, Sale $into): Sale
    {
        if ($from->is($into)) {
            throw ValidationException::withMessages(['into_sale_id' => ['Pick another tab to merge with.']]);
        }
        $this->assertNoOrderLevelExtras($from, 'merging');

        return DB::transaction(function () use ($from, $into) {
            $from = Sale::query()->whereKey($from->id)->lockForUpdate()->firstOrFail();
            $into = Sale::query()->whereKey($into->id)->lockForUpdate()->firstOrFail();

            // Voided lines too, so the void log still points at a sale that exists.
            SaleItem::withTrashed()->where('sale_id', $from->id)->update(['sale_id' => $into->id]);

            $next = (int) $into->kitchenTickets()->max('number');
            foreach ($from->kitchenTickets()->get() as $ticket) {
                $ticket->update(['sale_id' => $into->id, 'number' => ++$next]);
            }

            if (! $into->guests && $from->guests) {
                $into->guests = $from->guests;
            } elseif ($from->guests) {
                $into->guests += $from->guests;
            }
            $into->save();
            $into->recalcTotals();

            $from->delete();

            return $into->fresh();
        });
    }

    /**
     * Separate checks: the chosen lines (or part of a line) move to a new tab on the same table.
     *
     * @param  list<array{sale_item_id: int, quantity: float|int|string}>  $lines
     */
    public function split(Sale $from, array $lines, ?string $name, User $user): Sale
    {
        return DB::transaction(function () use ($from, $lines, $name, $user) {
            $from = Sale::query()->whereKey($from->id)->lockForUpdate()->firstOrFail();
            $items = $from->saleItems()->with(['modifiers', 'product'])->get()->keyBy('id');

            $moving = [];
            foreach ($lines as $i => $line) {
                $item = $items->get((int) $line['sale_item_id']);
                $quantity = Quantity::round($line['quantity']);
                if (! $item) {
                    throw ValidationException::withMessages(["lines.{$i}.sale_item_id" => ['That item is not on this tab.']]);
                }
                if ($quantity <= 0 || $quantity > (float) $item->quantity) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => ["Move between 0 and {$item->quantity} of {$item->product?->name}."]]);
                }
                if (! Quantity::fits($item->product, $quantity)) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => ["{$item->product?->name} is sold by the piece: move a whole number."]]);
                }
                $partial = $quantity < (float) $item->quantity;
                if ($partial && ((float) $item->discount > 0 || $item->discounts()->exists())) {
                    throw ValidationException::withMessages(["lines.{$i}.quantity" => ["{$item->product?->name} has a discount: move the whole line, or remove the discount first."]]);
                }
                $moving[] = [$item, $quantity, $partial];
            }

            $left = $items->sum('quantity') - collect($moving)->sum(fn ($m) => $m[1]);
            if ($left <= 0) {
                throw ValidationException::withMessages(['lines' => ['Leave at least one item on this tab.']]);
            }

            $new = Sale::query()->create([
                'user_id' => $user->id,
                'opened_by' => $user->id,
                'location_id' => $from->location_id,
                'domain' => $from->domain,
                'payment_status' => 'pending',
                'is_tab' => true,
                'dining_table_id' => $from->dining_table_id,
                'tab_name' => filled($name) ? trim($name) : trim(($from->tab_name ? $from->tab_name.' ' : '').'(split)'),
                'invoice_number' => Str::random(10),
                'transaction_date' => now(),
            ]);

            foreach ($moving as [$item, $quantity, $partial]) {
                if (! $partial) {
                    $item->update(['sale_id' => $new->id]);

                    continue;
                }

                // Part of a line: a copy with the moved amount (and its share of what was sent).
                $sent = min((float) $item->sent_quantity, $quantity);
                $copy = $item->replicate(['subtotal']);
                $copy->sale_id = $new->id;
                $copy->quantity = $quantity;
                $copy->sent_quantity = $sent;
                $copy->save();
                foreach ($item->modifiers as $modifier) {
                    $copy->modifiers()->create($modifier->replicate(['sale_item_id'])->getAttributes());
                }

                $item->update([
                    'quantity' => Quantity::round((float) $item->quantity - $quantity),
                    'sent_quantity' => Quantity::round(max(0, (float) $item->sent_quantity - $sent)),
                ]);
            }

            $from->recalcTotals();
            $new->recalcTotals();

            return $new->fresh();
        });
    }

    /**
     * Close a tab without paying. An empty one just goes; one with items is a void, approved with
     * a manager's PIN, and stays on record like a voided sale.
     */
    public function cancel(Sale $tab, User $user, ?string $pin, ?string $reason): void
    {
        DB::transaction(function () use ($tab, $user, $pin, $reason) {
            $tab = Sale::query()->whereKey($tab->id)->lockForUpdate()->firstOrFail();
            $live = $tab->saleItems()->get();

            if ($live->isEmpty() && ! SaleItem::onlyTrashed()->where('sale_id', $tab->id)->exists()) {
                $tab->delete();

                return;
            }

            if ($live->isNotEmpty() && ! filled($pin)) {
                throw ValidationException::withMessages(['pin' => ['A manager\'s PIN is needed to cancel a tab with items.']]);
            }
            $approver = $live->isNotEmpty() ? ManagerApproval::approverFor((string) $pin, $tab->domain, 'pin') : null;

            foreach ($live as $item) {
                VoidLog::query()->create([
                    'sale_item_id' => $item->id,
                    'user_id' => $user->id,
                    'approver_id' => $approver,
                    'reason' => $reason ?: 'Tab cancelled',
                    'amount' => $item->unit_price,
                ]);
                $item->delete();
            }

            $tab->recalcTotals();
            $tab->update([
                'payment_status' => 'voided',
                'voided_at' => now(),
                'voided_by' => $user->id,
                'void_approved_by' => $approver,
                'void_reason' => $reason ?: 'Tab cancelled',
            ]);
        });
    }

    /**
     * Send what is new on the tab to the kitchen: per line, what was added since the last send.
     * Returns null when there is nothing new. A take-out order (not a tab) is sent when it is paid,
     * under its own label.
     */
    public function sendToKitchen(Sale $tab, User $user, ?string $label = null): ?KitchenTicket
    {
        return DB::transaction(function () use ($tab, $user, $label) {
            $tab = Sale::query()->whereKey($tab->id)->lockForUpdate()->firstOrFail();
            $tab->load('diningTable');
            $items = $tab->saleItems()->with(['product', 'modifiers'])->orderBy('id')->get()
                ->filter(fn (SaleItem $i) => Quantity::round((float) $i->quantity - (float) $i->sent_quantity) > 0);

            if ($items->isEmpty()) {
                return null;
            }

            $number = (int) $tab->kitchenTickets()->max('number') + 1;
            $ticket = KitchenTicket::query()->create([
                'domain' => $tab->domain,
                'sale_id' => $tab->id,
                'location_id' => $tab->location_id,
                'number' => $number,
                'label' => $label ?? $tab->tabLabel(),
                'is_addition' => $number > 1,
                'sent_by' => $user->id,
            ]);

            foreach ($items as $item) {
                $new = Quantity::round((float) $item->quantity - (float) $item->sent_quantity);
                $ticket->items()->create([
                    'sale_item_id' => $item->id,
                    'name' => $item->product?->name ?? 'Item',
                    'modifiers' => $item->modifiers->map(fn ($m) => $m->name)->values()->all(),
                    'quantity' => $new,
                    'notes' => $item->notes,
                ]);
                $item->update(['sent_quantity' => $item->quantity]);
            }

            return $ticket->load(['items', 'sender']);
        });
    }

    /**
     * A sale at a dine-in store was just paid: it gets its order type, a take-out order goes to the
     * kitchen, and a table whose last tab this was waits to be cleared (when the store asks for that).
     * Returns the take-out kitchen ticket, if one was printed.
     */
    public function afterPaid(Sale $sale, ?InventoryLocation $location, User $user): ?KitchenTicket
    {
        // Dine-in is coming soon (config features.dine_in): a till sale gets no order type and no
        // kitchen ticket; a tab opened before it was switched off still closes as dine-in.
        if (! $sale->is_tab && ! config('features.dine_in', false)) {
            return null;
        }
        $type = $sale->orderTypeAt($location);
        if ($type === null) {
            return null;
        }
        $sale->forceFill(['order_type' => $type])->save();

        if ($type === Sale::ORDER_TAKE_OUT) {
            $name = $sale->customer?->name;

            return $this->sendToKitchen($sale, $user, 'Take-out · '.($name ?: '#'.$sale->invoice_number));
        }

        $table = $sale->dining_table_id ? DiningTable::query()->find($sale->dining_table_id) : null;
        if ($table && $location?->table_cleaning && ! $table->openTabs()->exists()) {
            $table->update(['needs_cleaning_at' => now()]);
        }

        return null;
    }

    /** Staff cleared the table: free for the next guests. */
    public function markReady(DiningTable $table): void
    {
        $table->update(['needs_cleaning_at' => null]);
    }

    /** Order discounts, a customer's points or a credit charge would not survive a merge. */
    private function assertNoOrderLevelExtras(Sale $tab, string $doing): void
    {
        if ($tab->saleDiscounts()->exists()) {
            throw ValidationException::withMessages(['tab' => ["Remove the order discount on {$tab->tabLabel()} before {$doing}."]]);
        }
        if ((float) ($tab->loyalty_points_redeemed ?? 0) > 0) {
            throw ValidationException::withMessages(['tab' => ["Take off the points used on {$tab->tabLabel()} before {$doing}."]]);
        }
    }
}
