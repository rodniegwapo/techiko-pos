<?php

namespace App\Http\Controllers;

use App\Mail\TestEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class MailTestController extends Controller
{
    /**
     * Show the super-user mail test form.
     */
    public function create(): Response
    {
        return Inertia::render('Mail/Test');
    }

    /**
     * Send a synchronous test email to the given address.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $email = $validated['email'];

        try {
            Mail::to($email)->send(new TestEmail);
        } catch (Throwable $e) {
            report($e);

            $detail = trim($e->getMessage()) ?: $e::class;
            $previous = $e->getPrevious();
            if ($previous && trim($previous->getMessage()) !== '' && ! str_contains($detail, $previous->getMessage())) {
                $detail .= ' ('.$previous->getMessage().')';
            }

            return back()->with('error', 'Could not send the test email: '.$detail);
        }

        return back()->with('success', 'Test email sent to '.$email.'.');
    }
}
