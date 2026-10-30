<?php

namespace App\Support;

use App\Models\Design;
use App\Models\Driver;
use Illuminate\Support\Facades\Storage;

/**
 * What is in a design's download, as the design page lists it: every file of the design and of each driver band with its
 * plain name, a type label and its size. The files are on the private disk; this only reads names and sizes (a file that
 * is no longer there is skipped) and never produces a link to them: downloads go through the snapshot routes.
 */
final class DesignFileList
{
    /** extension => label shown to buyers */
    private const TYPES = [
        'pdf' => 'PDF document',
        'dxf' => 'DXF CAD drawing',
        'dwg' => 'DWG CAD drawing',
        'step' => 'STEP CAD model',
        'stp' => 'STEP CAD model',
        'iges' => 'IGES CAD model',
        'igs' => 'IGES CAD model',
        'stl' => 'STL 3D model',
        'skp' => 'SketchUp model',
        'f3d' => 'Fusion 360 model',
        'svg' => 'SVG drawing',
        'xls' => 'Spreadsheet',
        'xlsx' => 'Spreadsheet',
        'ods' => 'Spreadsheet',
        'csv' => 'Spreadsheet (CSV)',
        'doc' => 'Word document',
        'docx' => 'Word document',
        'odt' => 'Text document',
        'rtf' => 'Text document',
        'md' => 'Text',
        'txt' => 'Text',
        'png' => 'Image',
        'jpg' => 'Image',
        'jpeg' => 'Image',
        'gif' => 'Image',
        'webp' => 'Image',
        'bmp' => 'Image',
        'tif' => 'Image',
        'tiff' => 'Image',
        'frd' => 'FRD frequency response',
        'zma' => 'ZMA impedance',
        'xsim' => 'XSim crossover project',
        'xmf' => 'XSim crossover project',
        'pcb' => 'Circuit board layout',
        'sch' => 'Schematic',
        'gbr' => 'Gerber circuit board file',
        'zip' => 'ZIP archive',
        '7z' => 'Archive',
        'rar' => 'Archive',
        'gz' => 'Archive',
    ];

    /**
     * The groups of files, in the order they are shown. Empty groups are left out.
     *
     * @return array<int, array{title: string, files: array<int, array{name: string, type: string, size: int}>}>
     */
    public static function groups(Design $design): array
    {
        $groups = [
            ['Enclosure files', $design->enclosure_files],
            ['Crossover / electronics files', $design->electronic_files],
            ['Other design files', $design->design_other_files],
            ['Response previews', $design->frd_files],
        ];

        foreach ($design->components as $component) {
            $band = trim($component->position.' '.($component->driver?->fullName() ?? 'driver'));

            $groups[] = [$band.': frequency response files', $component->frequency_files];
            $groups[] = [$band.': impedance files', $component->impedance_files];
            $groups[] = [$band.': other measurement files', $component->other_files];
        }

        $result = [];
        foreach ($groups as [$title, $paths]) {
            $files = self::files((array) $paths);

            if ($files !== []) {
                $result[] = ['title' => $title, 'files' => $files];
            }
        }

        return $result;
    }

    /**
     * The measurement files of a driver (manufacturer data), grouped like a design band's. Empty groups are left out.
     *
     * @return array<int, array{title: string, files: array<int, array{name: string, type: string, size: int}>}>
     */
    public static function driverGroups(Driver $driver): array
    {
        $result = [];
        foreach ([
            ['Frequency response files', $driver->frequency_files],
            ['Impedance files', $driver->impedance_files],
            ['Other measurement files', $driver->other_files],
        ] as [$title, $paths]) {
            $files = self::files((array) $paths);

            if ($files !== []) {
                $result[] = ['title' => $title, 'files' => $files];
            }
        }

        return $result;
    }

    /**
     * @param  array<int, mixed>  $paths
     * @return array<int, array{name: string, type: string, size: int}>
     */
    public static function files(array $paths): array
    {
        $disk = Storage::disk('private');
        $files = [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! $disk->exists($path)) {
                continue;
            }

            $files[] = ['name' => basename($path), 'type' => self::typeLabel($path), 'size' => (int) $disk->size($path)];
        }

        return $files;
    }

    public static function typeLabel(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? ($extension === '' ? 'File' : strtoupper($extension).' file');
    }

    /** 812 B, 48 KB, 3.4 MB. */
    public static function size(int $bytes): string
    {
        return match (true) {
            $bytes < 1024 => $bytes.' B',
            $bytes < 1024 * 1024 => round($bytes / 1024).' KB',
            default => number_format($bytes / 1048576, 1).' MB',
        };
    }
}
