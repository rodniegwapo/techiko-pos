<?php

namespace App\Http\Controllers;

use App\Models\Terminal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TerminalController extends Controller
{
    public function setupTerminal(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string',
            'password' => 'required|string',
        ]);

        // Check if password matches the authenticated user's password
        if (! Hash::check($validated['password'], auth()->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }
        
        $terminal = Terminal::updateOrCreate(
            ['name' => $validated['name']], // lookup condition
            [
                'device_identifier' => (string) Str::uuid(),
            ]
        );

        return response()->json([
            'uuid' => $terminal->device_identifier,
        ]);
    }
}
