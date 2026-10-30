<?php

namespace App\Support;

/**
 * The factory (datasheet) specs of a driver, as the driver page lists them: the Thiele/Small parameters in a fixed
 * order with a plain name and their unit. Values are the strings members typed in the dashboard (KeyValue field);
 * empty ones are skipped and keys this class does not know are listed after the known ones, without a unit.
 */
final class DriverSpecs
{
    /** key => [name, unit] in the order they are shown (the keys of CatalogueActions::specKeys()) */
    public const PARAMETERS = [
        'Re' => ['DC resistance', 'Ω'],
        'Fs' => ['Resonance frequency', 'Hz'],
        'Qms' => ['Mechanical Q', ''],
        'Qes' => ['Electrical Q', ''],
        'Qts' => ['Total Q', ''],
        'Rms' => ['Mechanical resistance', 'kg/s'],
        'Mms' => ['Moving mass', 'g'],
        'Cms' => ['Suspension compliance', 'mm/N'],
        'Vas' => ['Equivalent volume', 'L'],
        'Sd' => ['Piston area', 'cm²'],
        'BL' => ['Force factor', 'T·m'],
        'Xmax' => ['Linear excursion', 'mm'],
        'Le' => ['Voice-coil inductance', 'mH'],
        'SPL' => ['Sensitivity', 'dB'],
        'EBP' => ['Efficiency bandwidth product', ''],
        'Vd' => ['Displaced volume', 'cm³'],
        'Mmd' => ['Diaphragm mass', 'g'],
    ];

    /**
     * @param  array<string, mixed>|null  $specs  Driver::$factory_specs
     * @return array<int, array{key: string, name: ?string, value: string, unit: string}>
     */
    public static function rows(?array $specs): array
    {
        $given = [];
        foreach ((array) $specs as $key => $value) {
            if (is_scalar($value) && trim((string) $value) !== '' && trim((string) $key) !== '') {
                $given[strtolower(trim((string) $key))] = [trim((string) $key), trim((string) $value)];
            }
        }

        $rows = [];
        foreach (self::PARAMETERS as $key => [$name, $unit]) {
            if (isset($given[strtolower($key)])) {
                $value = $given[strtolower($key)][1];
                // a value that already carries its unit ("5.2 Ω") is shown as typed
                $rows[] = ['key' => $key, 'name' => $name, 'value' => $value, 'unit' => is_numeric($value) ? $unit : ''];
                unset($given[strtolower($key)]);
            }
        }

        foreach ($given as [$key, $value]) {
            $rows[] = ['key' => $key, 'name' => null, 'value' => $value, 'unit' => ''];
        }

        return $rows;
    }
}
