<?php

namespace App\Http\Livewire;

use Livewire\Component;
use App\Models\Design;
use App\Services\Forum\DesignDiscussionAudience;

class DesignDescription extends Component
{
    public Design $design;
    public string $activeTab = '';
    public string $reviewContent = '';
    public string $reviewTitle = '';
    public int $reviewRating = 5;
    public array $designReviews = [];
    public int $designReviewQty = 0;
    public ?float $designRating = null;

    public function mount(Design $design)
    {
        $this->design = $design;
        $this->designReviews = $design->reviews()->get()->toArray();
        $this->designReviewQty = $design->numberOfReviews();
        $this->designRating = $design->averageRating();
        if ($this->hasAccess) {
            $this->activeTab = 'tab2';
        } else {
            $this->activeTab = 'tab1';
        }
    }

    public function submitReview()
    {
        $this->validate([
            'reviewContent' => 'required|min:2',
            'reviewTitle' => 'required|min:3',
            'reviewRating' => 'required|integer|min:1|max:5',
        ]);

        $author = auth()->user();
        $this->design->review($this->reviewContent, $author, $this->reviewRating, $this->reviewTitle);
        $this->reviewContent = '';
        $this->reviewTitle = '';
        $this->reviewRating = 5;
        $this->designReviews = $this->design->reviews()->get()->toArray();
        $this->designReviewQty = $this->design->numberOfReviews();
        $this->designRating = $this->design->averageRating();
        
        session()->flash('message', 'Review submitted successfully!');
    }

    public function setActiveTab(string $tab)
    {
        $this->activeTab = $tab;
    }

    public function getAvailableTabsProperty()
    {
        $tabs = ['description' => true];

        if ($this->design->bill_of_materials) {
            $tabs['materials'] = true;
        }

        if ($this->design->forum_slug) {
            $tabs['forum'] = true;
        }

        return $tabs;
    }

    /** Hidden and paid designs have a private forum discussion: only the designer, the buyers and the admins can open it. */
    public function getCanSeeDiscussionProperty(): bool
    {
        return DesignDiscussionAudience::includes($this->design, auth()->user());
    }

    public function getHasAccessProperty()
    {
        // Not logged in
        if (!auth()->check()) {
            return false;
        }

        // Owner, admin, an entitlement (purchased or added for free), or a free design. This check
        // has no side effects: the free entitlement is granted when the user actually downloads.
        return auth()->user()->hasAccessTo($this->design, false);
    }

    public function render()
    {
        return view('livewire.design-description');
    }
}
