<?php

namespace App\Http\Livewire;

use App\Models\Driver;
use App\Support\FrequencyResponse;
use App\Support\ResponseChartData;
use Livewire\Component;

/**
 * The "Measured response" sheet of the driver page: the manufacturer's frequency response files drawn by the same chart
 * as the design page (<x-design.response-chart>, Alpine responseChart). Several files of one driver (on and off axis)
 * are separate lines and are not added up. The files are source files on the private disk and are only read
 * server-side (App\Support\FrequencyResponse).
 */
class DriverFrequencyResponseViewer extends Component
{
    public Driver $driver;

    public function mount(Driver $driver): void
    {
        $this->driver = $driver;
    }

    public function render()
    {
        $files = array_values((array) ($this->driver->frequency_files ?? []));

        return view('livewire.driver-frequency-response-viewer', [
            'chart' => ResponseChartData::from(FrequencyResponse::forFiles($files), false),
        ]);
    }
}
