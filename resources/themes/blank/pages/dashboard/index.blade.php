<?php
    use function Laravel\Folio\{middleware, name};
	middleware('auth');
    name('dashboard');
?>

<x-layouts.app>
    @volt('dashboard')
<div class="max-w-2xl space-y-3">
	<h1 class="text-2xl font-semibold tracking-tight text-ink">Welcome to the SDLabs.cc Beta!</h1>
<p class="!text-ink-2">If you have questions, please head to the Soap Box to read an introduction and some walkthroughs about what SDLabs.cc is about!</p>
    <p class="!text-ink-2">feel free to email Adrian at admin@sdlabs.cc for any further questions or discussions!</p>
    <div class="flex flex-wrap gap-2 pt-2">
    <x-button tag="a" href="https://www.sdlabs.cc/blog/site-news/welcome-to-the-sdlabscc-beta">Read the introduction here</x-button>
    <x-button tag="a" href="https://www.sdlabs.cc/blog/tutorials/how-to-use-sdlabscc">How to use SDLabs.cc</x-button>
    <x-button tag="a" href="https://www.sdlabs.cc/blog/">Browse the SoapBox!</x-button>
    </div>
</div>
    @endvolt
</x-layouts.app>
