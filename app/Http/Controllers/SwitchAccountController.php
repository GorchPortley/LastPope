<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The button on the "this link is for another account" page (EnsureLinkRecipient): signs the current account out and
 * sends the visitor to the login, which brings them back to the link afterwards. Only a path on this site is accepted
 * as the way back, never another host.
 */
class SwitchAccountController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $to = $request->validate([
            'to' => ['required', 'string', 'max:2000', 'starts_with:/', 'not_regex:#^//|\\\\#'],
        ])['to'];

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->put('url.intended', url($to));

        return redirect()->route('login');
    }
}
