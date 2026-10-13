<?php

namespace App\Services\Ai\Actions\Customers;

use App\Models\Customer;
use App\Services\Ai\Actions\ActionContext;
use App\Services\Ai\Actions\ActionInputException;

/** Finds a customer of this business by the id the AI got from find_customer. */
class CustomerLookup
{
    public static function find(mixed $id, ActionContext $context): Customer
    {
        $customer = $id ? Customer::query()->forDomain($context->domain->name_slug)->find($id) : null;
        if (! $customer) {
            throw new ActionInputException('No such customer in this business. Use find_customer to get their id.');
        }

        return $customer;
    }
}
