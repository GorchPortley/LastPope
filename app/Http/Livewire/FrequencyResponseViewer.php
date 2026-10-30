<?php

namespace App\Http\Livewire;

use App\Support\FrequencyResponse;
use Livewire\Component;

// Measurement files are source files: they live on the private disk and are only read server-side here.

class FrequencyResponseViewer extends Component
{
    public $design;
    public array $chartData = [];
    public array $summedResponse = [];
    /** Phase lines (not drawn by the chart any more; kept for anything that reads them). */
    public array $phaseData = [];
    public string $activeTab = 'amplitude';

    public function mount($design)
    {
        $this->design = $design;


        // Process data if not cached
        $data = $this->processAllData();
        $this->setData($data);
    }

    private function processAllData(): array
    {
        if (empty($this->design->frd_files)) {
            return [];
        }

        // parsing, colours, the summed response and the phase lines are shared with the design PDF
        return FrequencyResponse::forFiles($this->getFrdFiles());
    }

    private function setData(array $data): void
    {
        $this->chartData = $data['chartData'] ?? [];
        $this->summedResponse = $data['summedResponse'] ?? [];
        $this->phaseData = $data['phaseData'] ?? [];
    }

    private function getFrdFiles(): array
    {
        return is_string($this->design->frd_files)
            ? json_decode($this->design->frd_files, true)
            : $this->design->frd_files;
    }

    public function render()
    {
        return view('livewire.frequency-response-viewer', [
            'chartData' => $this->chartData,
            'summedResponse' => $this->summedResponse,
            'phaseData' => $this->phaseData,
            'activeTab' => $this->activeTab
        ]);
    }
}
