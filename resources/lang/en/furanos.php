<?php

return [
    'tab'            => 'Furans',
    'samples_tab'    => 'Samples',
    'trends_tab'     => 'Trends',
    'trends_hint'    => 'Evolution of furanic compounds (ppb) across samples.',
    'trends_no_data' => 'At least 2 samples are needed to show trends.',
    'singular'       => 'Sample',
    'sample_date'    => 'Date',
    'state'          => 'State',
    'dp'             => 'DP',
    'dp_full'        => 'Degree of polymerization (Chendong)',
    'compounds'      => 'Furanic compounds (ppb)',
    'new_test'       => 'New sample',
    'edit_test'      => 'Edit sample',
    'no_samples'     => 'No samples yet. Create the first one to diagnose.',
    'delete_confirm' => 'Delete the sample from :date?',
    'fal_required'   => '2-furfuraldehyde (FAL) is required.',
    'sample_date_required' => 'The sample date is required.',

    // Generation rate and interpretation warnings.
    'rate_label'        => '2-FAL generation rate',
    'rate_unit'         => 'ppb/year',
    'rate_help'         => 'Change in 2-FAL per year between the two latest samples. It shows how fast the paper is degrading: a high positive rate signals accelerated ageing; near zero, stable; a negative rate is usually due to oil treatment/replacement (furans are removed and the value drops artificially).',
    'oil_warning_title' => 'Oil treated or replaced',
    'oil_warning_body'  => 'The oil was treated/replaced on :date. Furans are removed by treatment, so later samples underestimate the real paper age.',
    // Action recommendation based on the latest sample.
    'reco_label'    => 'Recommendation',
    'reco_routine'  => 'Routine monitoring: paper in good shape, keep the usual sampling frequency.',
    'reco_monitor'  => 'Follow-up: watch the 2-FAL trend and resample earlier than usual.',
    'reco_increase' => 'Investigate: increase sampling frequency and plan a paper assessment; confirm with a measured DP.',
    'reco_critical' => 'Priority attention: high risk of paper degradation — assess inspection/replacement and confirm with a measured DP.',
    'reco_rising'   => 'Rising trend: shorten the sampling interval.',
    'reco_source'   => 'Maintenance recommendation based on 2-FAL severity. It is an action guide (practical criterion, in line with IEEE C57.104 / CIGRE), not a normed value: the diagnosis comes from the severity.',
    // At-a-glance diagnostic chips (KPIs in the top strip).
    'dp_chip'        => 'DP ≈ :dp',
    'dp_chip_help'   => 'Paper degree of polymerization estimated from 2-FAL (Chendong). New paper ≈ 1000; end of life ≈ 200.',
    'life_chip'      => 'Paper life :p % consumed',
    'life_chip_help' => 'Percentage of the paper mechanical life already consumed, estimated from DP. The higher the percentage, the more degraded the paper.',
    'fal_chip'       => '2-FAL :fal ppb',
    'fal_chip_help'  => '2-furfuraldehyde concentration of the latest sample (the furan that diagnoses paper degradation).',
    // Diagnosis + Conclusions block (narrative of the latest sample).
    'diag' => [
        'title'      => 'Diagnosis',
        'concl'      => 'Conclusions and recommendations',
        'no_data'    => 'No 2-FAL measured in the latest sample: furans cannot be diagnosed.',
        'value'      => 'The 2-FAL of the latest sample is :fal ppb (:ppm ppm).',
        'exceeds'    => 'It exceeds the healthy-paper threshold (≈ :safe ppb): indicates degradation of the cellulose insulation.',
        'within'     => 'It is within the healthy-paper range (≤ :safe ppb).',
        'dp'         => 'Estimated degree of polymerization DP ≈ :dp (Chendong correlation; CIGRE TB 494 / IEEE C57.91), equivalent to ~:life % of the paper mechanical life consumed.',
        'state'      => 'Paper condition per furans: :condition.',
        'rate'       => '2-FAL generation rate between the two latest samples: :rate ppb/year.',
        'foot'       => 'The DP shown is estimated from 2-FAL (Chendong correlation) and is an indicative value. Definitive confirmation requires a direct DP measurement on a paper sample.',
    ],
    // CO₂/CO ratio from the latest DGA (paper cross-check, IEC 60599).
    'co_ratio_low'    => 'CO₂/CO < 3: possible thermal degradation of the paper (cellulose). Reinforces the furan diagnosis.',
    'co_ratio_normal' => 'CO₂/CO between 3 and 10: normal range, no clear sign of paper fault.',
    'co_ratio_high'   => 'CO₂/CO > 10: possible low-temperature fault or paper oxidation.',
    // Degradation mechanism from the dominant secondary furan (indicative).
    'mech_oxidation' => 'possible oxidation',
    'mech_moisture'  => 'possible moisture/hydrolysis',
    'mech_thermal'   => 'possible overheating',
    'mech_abnormal'  => 'abnormal pattern',
    'mech_caveat'    => 'Dominant secondary furan. Indicative of the degradation mechanism: secondary-furan interpretation is less established than 2-FAL. Source: A. De Pablo, CIGRE Electra 175 (1997) — qualitative association, no normed thresholds.',
    // PDF report.
    'report_open'      => 'PDF report',
    'report_help'      => 'Download a PDF report with the diagnosis, trends and the samples table.',
    'report_title'     => 'Furan report',
    'report_generated' => 'Generated',
    'report_footer'    => 'Diagnosis estimated from 2-FAL; confirm with a direct DP measurement when possible.',

    'created' => 'Furan sample created and diagnosed.',
    'saved'   => 'Furan sample updated.',
    'deleted' => 'Furan sample deleted.',

    // Compounds (code → full name, for column tooltips).
    // Abbreviations per standard nomenclature (IEC 61198): position-prefixed.
    'fal' => '2-furfuraldehyde (2FAL)',
    'hme' => '5-hydroxymethyl-2-furfural (5HMF)',
    'ace' => '2-acetylfuran (2ACF)',
    'mfu' => '5-methyl-2-furfuraldehyde (5MEF)',
    'fua' => '2-furfuryl alcohol (2FOL)',

    // "Why this result?" drawer (diagnosis trace)
    'explain' => [
        'open'       => 'See how it was calculated',
        'title'      => 'Why this result?',
        'subtitle'   => 'Diagnosis trace — the 2-FAL threshold and the DP formula live in data; the code only applies them.',
        'result'     => 'Result',
        'no_value'   => 'No 2-FAL measured, so it cannot be diagnosed.',
        'conversion' => 'Conversion',
        'fal_ppb'    => 'Measured 2-FAL',
        'ppm'        => '2-FAL in ppm',
        'dp'         => 'Degree of polymerization (DP)',
        'life'       => 'Paper life consumed',
        'life_hint'  => '(DP 1000 new → 200 end of life)',
        'dp_formula' => 'DP = (1.51 − log₁₀(2-FAL ppm)) ÷ 0.0035  ·  Chendong',
        'formula'    => 'Formula',
        'scale'      => 'Scale (where the 2-FAL falls, in ppm)',
        'your_value' => 'Your 2-FAL',
        'status'     => 'Status',
        'rating'     => 'Rating',
        'loading'    => 'Calculating…',
        'reference'        => 'Diagnosis source',
        'source_formula'   => 'DP formula',
        'source_scale'     => 'Scale (bands)',
        'status_confirmed'   => 'Backed by source',
        'status_unconfirmed' => 'To be confirmed',
        'paper_warning_title' => 'Thermally upgraded paper',
        'paper_warning_body'  => 'This transformer has thermally upgraded paper, which produces less 2-FAL by design. The result and estimated DP may underestimate the real paper degradation: rely on direct DP measurement and the other tests.',
    ],

    // Diagnosis source citations (shown in the drawer). Status and URL live in
    // config/diagnostic_sources.php; the text is translated here.
    'sources' => [
        'formula_citation' => 'Chendong correlation — X. Chendong, "Monitoring paper insulation ageing by measuring furfural contents in oil", 7th ISH, Dresden, 1991. Adopted in CIGRE TB 494 (2012) and IEEE C57.104-2019.',
        'scale_citation'   => '2-FAL bands (ppm) anchored to paper degree-of-polymerization (DP) milestones: <0.1→DP>700 (new) · 0.1-0.5→DP 500-700 · 0.5-2→DP 350-500 · 2-6→DP 200-350 · >6→DP≤200 (end of life). Reference: CIGRE TB 494 (2012) and IEEE C57.91 (DP ~200 = paper end of life).',
    ],
];
