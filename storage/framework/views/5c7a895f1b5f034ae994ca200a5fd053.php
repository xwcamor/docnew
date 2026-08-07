<!DOCTYPE html>
<html lang="<?php echo e(app()->getLocale()); ?>">
<head>
    <meta charset="utf-8">
    <title><?php echo e($title); ?></title>
    <style>
        /*
         | Reglas para PDFs en DomPDF (clon del template de Regions):
         |
         | 1) font-family: solo "Helvetica" (PDF core font).
         | 2) font-weight: SOLO `normal` o `bold`. Numericos como 600 no andan.
         | 3) Evitar `position: fixed` con offsets negativos para hdrs/footers.
         | 4) `display: inline-block` con `background-color` puede estirarse —
         |    para badges usamos texto coloreado plano.
         */

        @page {
            margin: 24px 28px 24px 28px;
        }

        body {
            font-family: Helvetica;
            font-size: 9pt;
            color: #32363A;
            margin: 0;
        }

        /* Brand band */
        .brand-band {
            background: #354A5F;
            color: #ffffff;
            padding: 14px 18px;
            margin-bottom: 14px;
        }
        .brand-band__meta {
            float: right;
            font-size: 8pt;
            color: #cbd5e1;
            text-align: right;
            line-height: 1.4;
        }
        .brand-band__meta strong { color: #ffffff; font-weight: bold; }
        .brand-band__title {
            font-size: 14pt;
            font-weight: bold;
            margin: 0;
            letter-spacing: 0.01em;
        }
        .brand-band__sub {
            font-size: 8pt;
            color: #cbd5e1;
            margin: 4px 0 0 0;
        }

        /* Filters summary box */
        .filters {
            background: #F0F6FB;
            border-left: 3px solid #0A6ED1;
            padding: 8px 12px;
            margin: 0 0 12px 0;
            font-size: 8.5pt;
            color: #334155;
        }
        .filters__title {
            display: block;
            font-weight: bold;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #0A6ED1;
            margin: 0 0 4px 0;
        }
        .filters__list { margin: 0; padding: 0; list-style: none; }
        .filters__list li { line-height: 1.5; }
        .filters__list li b { font-weight: bold; color: #1f2937; }

        /* Counter */
        .counter {
            font-size: 8.5pt;
            color: #6A6D70;
            margin: 0 0 8px 0;
        }
        .counter strong { color: #1f2937; font-weight: bold; }

        /* Data table */
        table.data {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        table.data thead th {
            background: #0A6ED1;
            color: #ffffff;
            font-weight: bold;
            font-size: 9pt;
            text-align: left;
            padding: 6px 8px;
            border: 1px solid #085CAF;
        }
        table.data tbody td {
            padding: 5px 8px;
            border: 1px solid #E5E5E5;
            font-size: 8.5pt;
            color: #32363A;
        }
        table.data tbody tr:nth-child(even) td {
            background: #F8FAFC;
        }

        .status-active   { color: #1D7044; font-weight: bold; }
        .status-inactive { color: #C8281D; font-weight: bold; }

        .empty {
            text-align: center;
            padding: 32px 20px;
            color: #6A6D70;
            font-size: 9pt;
        }
        .doc-footer {
            margin-top: 16px;
            padding-top: 8px;
            border-top: 1px solid #E5E5E5;
            font-size: 7.5pt;
            color: #6A6D70;
            text-align: center;
        }
    </style>
</head>
<body>
    
    <div class="brand-band">
        <div class="brand-band__meta">
            <strong><?php echo e(config('app.name')); ?></strong><br>
            <?php echo e(__('global.created_by')); ?>: <?php echo e($generatedBy); ?>

        </div>
        <h1 class="brand-band__title"><?php echo e($title); ?></h1>
        <p class="brand-band__sub">
            <?php echo e(__('global.generated_at')); ?>: <?php echo e(now()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT)); ?>

        </p>
    </div>

    
    <?php if(!empty($filtersSummary)): ?>
        <div class="filters">
            <span class="filters__title"><?php echo e(__('global.filters_applied')); ?></span>
            <ul class="filters__list">
                <?php $__currentLoopData = $filtersSummary; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $f): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <li><b><?php echo e($f['label']); ?>:</b> <?php echo e($f['value']); ?></li>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </ul>
        </div>
    <?php endif; ?>

    
    <p class="counter">
        <?php echo e(trans_choice('global.records_in_report', $totalCount, ['count' => $totalCount])); ?>

    </p>

    
    <?php
        $headings = [
            'id'                 => __('customers.id'),
            'name'               => __('customers.name'),
            'cod'                => __('customers.cod'),
            'country'            => __('customers.country'),
            'address'            => __('customers.address'),
            'is_active'          => __('customers.is_active'),
            'locations_count'    => __('customers.locations'),
            'areas_count'        => __('customers.areas'),
            'substations_count'  => __('customers.substations'),
            'transformers_count' => __('customers.transformers'),
            'slug'               => 'Slug',
            'created_at'         => __('global.created_at'),
            'updated_at'         => __('global.updated_at'),
            'creator'            => __('global.created_by'),
        ];
    ?>

    <?php if($totalCount === 0): ?>
        <div class="empty">
            <?php echo e(__('global.no_matching_records')); ?>

        </div>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <?php $__currentLoopData = $columns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $col): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                        <th><?php echo e($headings[$col] ?? $col); ?></th>
                    <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                </tr>
            </thead>
            <tbody>
                <?php $__currentLoopData = $customers; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $customer): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <?php $__currentLoopData = $columns; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $col): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                            <td>
                                <?php switch($col):
                                    case ('id'): ?>        <?php echo e($customer->id); ?> <?php break; ?>
                                    <?php case ('name'): ?>      <?php echo e($customer->name); ?> <?php break; ?>
                                    <?php case ('cod'): ?>       <?php echo e($customer->cod ?? ''); ?> <?php break; ?>
                                    <?php case ('country'): ?>   <?php echo e($customer->country?->name ?? '—'); ?> <?php break; ?>
                                    <?php case ('address'): ?>   <?php echo e($customer->address ?? ''); ?> <?php break; ?>
                                    <?php case ('locations_count'): ?>    <?php echo e((int) ($customer->locations_count ?? 0)); ?> <?php break; ?>
                                    <?php case ('areas_count'): ?>        <?php echo e((int) ($customer->areas_count ?? 0)); ?> <?php break; ?>
                                    <?php case ('substations_count'): ?>  <?php echo e((int) ($customer->substations_count ?? 0)); ?> <?php break; ?>
                                    <?php case ('transformers_count'): ?> <?php echo e((int) ($customer->transformers_count ?? 0)); ?> <?php break; ?>
                                    <?php case ('is_active'): ?>
                                        <span class="<?php echo e($customer->is_active ? 'status-active' : 'status-inactive'); ?>">
                                            <?php echo e($customer->state_text); ?>

                                        </span>
                                    <?php break; ?>
                                    <?php case ('slug'): ?>       <?php echo e($customer->slug); ?> <?php break; ?>
                                    <?php case ('created_at'): ?> <?php echo e($customer->created_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT)); ?> <?php break; ?>
                                    <?php case ('updated_at'): ?> <?php echo e($customer->updated_at?->copy()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATETIME_FORMAT)); ?> <?php break; ?>
                                    <?php case ('creator'): ?>    <?php echo e($customer->creator->name ?? '—'); ?> <?php break; ?>
                                    <?php default: ?> <?php echo e($customer->{$col} ?? ''); ?>

                                <?php endswitch; ?>
                            </td>
                        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="doc-footer">
        <?php echo e(config('app.name')); ?> · <?php echo e(now()->setTimezone($tz ?? config('app.timezone'))->format(\App\Support\Tz::DATE_FORMAT)); ?>

    </div>
</body>
</html>
<?php /**PATH /workspace/docnew/resources/views/business_management/customers/pdf/template.blade.php ENDPATH**/ ?>