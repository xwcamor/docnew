<script setup>
/**
 * Tarjeta de branding en /system_management/settings.
 * Muestra el logo del sistema actual y permite subir uno nuevo o quitarlo.
 * El logo se guarda en storage/app/public/branding/ y el setting `app.logo_url`
 * recibe el path /storage/branding/...
 */
import { ref, computed } from 'vue';
import { router, usePage, useForm } from '@inertiajs/vue3';
import { Card, Button, Upload, Popconfirm, message } from 'ant-design-vue';
import { UploadOutlined, DeleteOutlined, PictureOutlined } from '@ant-design/icons-vue';
import { useI18n } from '@/Plugins/i18n';

const { t } = useI18n();
const page = usePage();

const currentLogo = computed(() => page.props.appLogoUrl || null);

const form = useForm({ logo: null });
const fileInput = ref(null);
const removing  = ref(false);

const beforeUpload = (file) => {
    form.logo = file;
    form.post(route('system_management.settings.branding.upload_logo'), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset('logo');
            message.success(t('settings.logo_uploaded'));
            router.reload({ only: ['appLogoUrl'] });
        },
        onError: (errors) => {
            const first = Object.values(errors)[0];
            if (first) message.error(first);
        },
    });
    return false;
};

const removeLogo = () => {
    removing.value = true;
    router.delete(route('system_management.settings.branding.remove_logo'), {
        preserveScroll: true,
        onSuccess: () => {
            message.success(t('settings.logo_removed'));
            router.reload({ only: ['appLogoUrl'] });
        },
        onFinish: () => { removing.value = false; },
    });
};
</script>

<template>
    <Card class="branding-card" :bodyStyle="{ padding: '16px 20px' }">
        <div class="branding-row">
            <div class="branding-preview">
                <img v-if="currentLogo" :src="currentLogo" :alt="$t('settings.branding_title')" />
                <div v-else class="branding-empty">
                    <PictureOutlined />
                    <span>{{ $t('settings.branding_no_logo') }}</span>
                </div>
            </div>

            <div class="branding-info">
                <h3>{{ $t('settings.branding_title') }}</h3>
                <p>{{ $t('settings.branding_hint') }}</p>
            </div>

            <div class="branding-actions">
                <Upload
                    :before-upload="beforeUpload"
                    :show-upload-list="false"
                    accept="image/png,image/jpeg,image/svg+xml,image/webp"
                >
                    <Button type="primary" :loading="form.processing">
                        <UploadOutlined />
                        {{ currentLogo ? $t('settings.branding_change') : $t('settings.branding_upload') }}
                    </Button>
                </Upload>

                <Popconfirm
                    v-if="currentLogo"
                    :title="$t('settings.branding_remove_confirm')"
                    :ok-text="$t('global.yes')"
                    :cancel-text="$t('global.no')"
                    @confirm="removeLogo"
                >
                    <Button danger ghost :loading="removing">
                        <DeleteOutlined />
                        {{ $t('settings.branding_remove') }}
                    </Button>
                </Popconfirm>
            </div>
        </div>
    </Card>
</template>

<style scoped>
/* margin-top separa la tarjeta del logo del título/consola de arriba. */
.branding-card { margin-top: 16px; margin-bottom: 16px; border-radius: 6px; }
.branding-row {
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}
.branding-preview {
    width: 180px;
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--color-surface-alt, #f6f7f9);
    border: 1px dashed var(--color-border, #d9d9d9);
    border-radius: 6px;
    overflow: hidden;
}
.branding-preview img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
}
.branding-empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    color: var(--color-text-muted, #888);
    font-size: 0.8125rem;
}
.branding-empty :deep(.anticon) { font-size: 24px; }
.branding-info { flex: 1; min-width: 220px; }
.branding-info h3 {
    margin: 0 0 4px 0;
    font-size: 1rem;
    font-weight: 600;
}
.branding-info p {
    margin: 0;
    font-size: 0.8125rem;
    color: var(--color-text-muted, #6a6d70);
    line-height: 1.4;
}
.branding-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

@media (max-width: 640px) {
    .branding-row { flex-direction: column; align-items: stretch; }
    .branding-preview { width: 100%; }
    .branding-actions { flex-direction: column; }
}
</style>
