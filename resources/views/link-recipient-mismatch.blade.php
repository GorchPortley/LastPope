{{-- EnsureLinkRecipient: a link from an e-mail or notification was opened while signed in as another account. --}}
<x-layouts.app>
    <x-app.container class="max-w-xl">
        <div class="p-6 bg-white border rounded-lg border-zinc-200 space-y-5" data-link-recipient-mismatch>
            <x-app.heading
                title="This link is for another account"
                description="It was sent to a different account, so the page is not shown here."
            />

            <p class="text-sm text-zinc-600">
                You are signed in as <strong class="text-zinc-900">{{ $user->email }}</strong>.
                To open the link, sign in with the account the e-mail was sent to.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('link.switch-account') }}">
                    @csrf
                    <input type="hidden" name="to" value="{{ $target }}">
                    <x-button type="submit">Sign in with the other account</x-button>
                </form>
                <a href="{{ route('dashboard') }}" class="text-sm font-medium text-zinc-600 hover:text-zinc-900">Back to my dashboard</a>
            </div>
        </div>
    </x-app.container>
</x-layouts.app>
