<?php

/*
 * This file is part of Flarum.
 *
 * For detailed copyright and license information, please view the
 * LICENSE file that was distributed with this source code.
 */

use Flarum\Extend;
use Flarum\Extension\Extension;
use FoF\Byobu\Events\AbstractRecipientsEvent;
use FoF\Byobu\Events\DiscussionMadePublic;
use FoF\Byobu\Listeners\CreatePostWhenRecipientsChanged;
use Illuminate\Contracts\Container\Container;

return [
    // Register extenders here to customize your forum!

    // LastPope: the shop decides who can see a design's thread (designer, buyers, admins; docs/PLAN.md R5.2) and changes
    // its recipients through the API as a forum administrator on every purchase, refund, price or status change. Byobu
    // would add a "forumadmin added recipients ..." line to the thread each time, which clutters it and shows everybody
    // who bought the design or got a refund. Recipient changes made by an administrator leave no line; members' own
    // private discussions keep them. Byobu subscribes this listener by class name, so binding the class swaps it.
    //
    // The line is not only cosmetic: Byobu stores the new recipients after the discussion is saved, and creating the
    // line saves the discussion once more (Post::created), which is when Flarum works out is_private again from the
    // stored recipients. Without the line that second save has to be done here, or the discussion keeps its old
    // privacy (public after "make private", invisible to everyone after "make public").
    new class implements Extend\ExtenderInterface {
        public function extend(Container $container, ?Extension $extension = null): void
        {
            if (! class_exists(CreatePostWhenRecipientsChanged::class)) {
                return;
            }

            $container->bind(CreatePostWhenRecipientsChanged::class, fn () => new class extends CreatePostWhenRecipientsChanged {
                public function whenDiscussionWasTagged(AbstractRecipientsEvent $event): void
                {
                    $event->actor->isAdmin() ? $this->resave($event) : parent::whenDiscussionWasTagged($event);
                }

                public function whenMadePublic(DiscussionMadePublic $event): void
                {
                    $event->actor->isAdmin() ? $this->resave($event) : parent::whenMadePublic($event);
                }

                /** What creating the event post would do to the discussion: save a fresh copy, so is_private is recomputed. */
                private function resave(AbstractRecipientsEvent $event): void
                {
                    if ($event->discussion->exists) {
                        $event->discussion->newQueryWithoutScopes()->find($event->discussion->getKey())?->save();
                    }
                }
            });
        }
    },
];
