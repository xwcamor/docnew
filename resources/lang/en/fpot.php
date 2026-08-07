<?php

return [
    'tab'         => 'Power Factor',
    'samples_tab' => 'Samples',
    'trends_tab'  => 'Trends',
    'singular'    => 'Sample',
    'plural'      => 'Samples',
    'title'       => 'Power Factor samples',
    'sample_date' => 'Date',
    'value'       => 'Power factor (%)',
    'value_help'  => 'Insulation power factor (num_fac), in %.',
    'temperature' => 'Temperature (°C)',
    'condition'   => 'Condition',
    'state'       => 'State',
    'add'         => 'New sample',
    'edit'        => 'Edit sample',
    'new_test'    => 'New sample',
    'edit_test'   => 'Edit sample',
    'empty'       => 'No samples yet. Create the first one to diagnose.',
    'no_samples'  => 'No samples yet. Create the first one to diagnose.',
    'trend_title' => 'Power Factor trend (%)',
    'trends_hint' => 'Evolution of power factor (%) across samples.',
    'trends_no_data' => 'At least 2 samples are needed to show trends.',
    'delete_confirm' => 'Delete the sample from :date?',
    'sample_date_required' => 'The sample date is required.',

    'created' => 'Power factor sample created and diagnosed.',
    'saved'   => 'Power factor sample updated.',
    'deleted' => 'Power factor sample deleted.',

    // "Why this result?" drawer (diagnosis trace)
    'explain' => [
        'open'       => 'See how it was calculated',
        'title'      => 'Why this result?',
        'subtitle'   => 'Diagnosis trace — the scale lives in data; the measured value enters as-is (no conversion).',
        'result'     => 'Result',
        'no_value'   => 'No power factor measured, so it cannot be diagnosed.',
        'value'      => 'Power factor (%)',
        'scale'      => 'Scale (where the value falls)',
        'your_value' => 'Your value',
        'rating'     => 'Rating',
        'loading'    => 'Calculating…',
    ],

    // Diagnosis + Conclusions block (narrative of the latest sample).
    'diag' => [
        'value'        => 'Measured power factor: :v % → :condition.',
        'over'         => 'Above the reference value (:limit %): indicates moisture or insulation ageing.',
        'near'         => 'In the last acceptable band, close to the reference value (:limit %), without exceeding it.',
        'concl_approaching' => 'Note: the power factor is approaching the reference value (:limit %) without exceeding it — resample and watch the trend.',
        'concl_routine'=> 'Within expectations: routine monitoring.',
        'concl_watch'  => 'High value: resample and watch the trend; correlate with moisture and acidity (physicochemical).',
        'concl_investigate' => 'Power factor out of range: schedule oil treatment/regeneration and correlate with moisture and acidity (physicochemical). Shorten the measurement interval.',
        'concl_source' => 'Operational conclusion based on the configured power-factor scale.',
        'foot'         => 'Power factor (tan δ) rises with moisture and insulation ageing. Evaluated against the system\'s configured scale.',
        'no_data'      => 'No power-factor measurement: cannot be diagnosed.',
    ],
];
