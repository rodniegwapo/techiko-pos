<script setup>
import { ref, computed, watch } from "vue";
import { router, useForm } from "@inertiajs/vue3";
import { IconX, IconWorld, IconMapPin, IconCurrencyDollar, IconClock } from "@tabler/icons-vue";
import { notification } from "ant-design-vue";

const props = defineProps({
    visible: {
        type: Boolean,
        default: false,
    },
    domain: {
        type: Object,
        default: null,
    },
    isEdit: {
        type: Boolean,
        default: false,
    },
    timezones: {
        type: Array,
        default: () => [],
        validator: (value) => Array.isArray(value)
    },
    currencies: {
        type: Array,
        default: () => [],
        validator: (value) => Array.isArray(value)
    },
    countries: {
        type: Array,
        default: () => [],
        validator: (value) => Array.isArray(value)
    },
});

const emit = defineEmits(['close', 'success']);

// Form handling
const form = useForm({
    name: '',
    name_slug: '',
    description: '',
    country_code: '',
    currency_code: '',
    timezone: '',
    date_format: 'Y-m-d',
    language_code: 'en',
    time_format: 'H:i:s',
    is_active: true,
});

// Populate form when editing
const populateForm = () => {
    if (props.isEdit && props.domain) {
        form.name = props.domain.name || '';
        form.name_slug = props.domain.name_slug || '';
        form.description = props.domain.description || '';
        form.country_code = props.domain.country_code || props.domain.country || '';
        form.currency_code = props.domain.currency_code || props.domain.currency || '';
        form.timezone = props.domain.timezone || '';
        form.date_format = props.domain.date_format || 'Y-m-d';
        form.language_code = props.domain.language_code || 'en';
        form.time_format = props.domain.time_format || 'H:i:s';
        form.is_active = props.domain.is_active !== undefined ? props.domain.is_active : true;
    } else {
        // Reset form for create mode
        form.name = '';
        form.name_slug = '';
        form.description = '';
        form.country_code = '';
        form.currency_code = '';
        form.timezone = '';
        form.date_format = 'Y-m-d';
        form.language_code = 'en';
        form.time_format = 'H:i:s';
        form.is_active = true;
    }
};

// Watch for changes in domain prop
watch(() => props.domain, populateForm, { immediate: true });
watch(() => props.isEdit, populateForm, { immediate: true });

// Form reference
const formRef = ref();

// Options for selects
const countryOptions = computed(() => {
    console.log('Countries prop:', props.countries);
    if (!Array.isArray(props.countries) || props.countries.length === 0) {
        // Provide some default countries if none are available
        return [
            { label: 'United States', value: 'US' },
            { label: 'Philippines', value: 'PH' },
            { label: 'Japan', value: 'JP' },
            { label: 'Singapore', value: 'SG' },
            { label: 'Malaysia', value: 'MY' },
        ];
    }
    return props.countries.map(country => ({
        label: typeof country === 'string' ? country : (country.name || country.label || country),
        value: typeof country === 'string' ? country : (country.code || country.value || country.name || country)
    }));
});

const currencyOptions = computed(() => {
    console.log('Currencies prop:', props.currencies);
    if (!Array.isArray(props.currencies) || props.currencies.length === 0) {
        // Provide some default currencies if none are available
        return [
            { label: 'USD - US Dollar', value: 'USD' },
            { label: 'PHP - Philippine Peso', value: 'PHP' },
            { label: 'JPY - Japanese Yen', value: 'JPY' },
            { label: 'SGD - Singapore Dollar', value: 'SGD' },
            { label: 'MYR - Malaysian Ringgit', value: 'MYR' },
        ];
    }
    return props.currencies.map(currency => ({
        label: typeof currency === 'string' ? currency : (currency.name || currency.label || currency),
        value: typeof currency === 'string' ? currency : (currency.code || currency.value || currency.name || currency)
    }));
});

const timezoneOptions = computed(() => {
    console.log('Timezones prop:', props.timezones);
    if (!Array.isArray(props.timezones) || props.timezones.length === 0) {
        // Provide some default timezones if none are available
        return [
            { label: 'America/New_York', value: 'America/New_York' },
            { label: 'Asia/Manila', value: 'Asia/Manila' },
            { label: 'Asia/Tokyo', value: 'Asia/Tokyo' },
            { label: 'Asia/Singapore', value: 'Asia/Singapore' },
            { label: 'Asia/Kuala_Lumpur', value: 'Asia/Kuala_Lumpur' },
        ];
    }
    return props.timezones.map(timezone => ({
        label: typeof timezone === 'string' ? timezone : (timezone.name || timezone.label || timezone),
        value: typeof timezone === 'string' ? timezone : (timezone.value || timezone.name || timezone)
    }));
});

// Form validation rules
const rules = computed(() => ({
    name: [{ required: true, message: "Please enter domain name" }],
    name_slug: [{ required: true, message: "Please enter domain slug" }],
    country_code: [{ required: true, message: "Please select a country" }],
    currency_code: [{ required: true, message: "Please select a currency" }],
    timezone: [{ required: true, message: "Please select a timezone" }],
    date_format: [{ required: true, message: "Please select a date format" }],
    language_code: [{ required: true, message: "Please select a language" }],
    time_format: [{ required: true, message: "Please select a time format" }],
}));

// Filter function for timezone search
const filterOption = (input, option) => {
    return option.label.toLowerCase().indexOf(input.toLowerCase()) >= 0;
};

// Computed property for auto-generated slug
const generatedSlug = computed(() => {
    if (form.name) {
        return form.name
            .toLowerCase()
            .replace(/[^a-z0-9\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .trim('-');
    }
    return '';
});

// Watch for name changes to auto-generate slug
const updateSlug = () => {
    if (form.name && !form.name_slug) {
        form.name_slug = generatedSlug.value;
    }
};

// Methods
const handleSubmit = async () => {
    try {
        if (!formRef.value) {
            console.warn('Form reference not available');
            return;
        }
        
        await formRef.value.validate();
        
        const routeName = props.isEdit ? 'domains.update' : 'domains.store';
        const routeParams = props.isEdit ? { domain: props.domain.id } : {};
        
        if (props.isEdit) {
            form.put(route(routeName, routeParams), {
                onSuccess: () => {
                    notification.success({
                        message: 'Success',
                        description: 'Domain updated successfully!',
                    });
                    emit('success');
                    resetForm();
                },
                onError: (errors) => {
                    notification.error({
                        message: 'Error',
                        description: 'Please check the form for errors.',
                    });
                },
            });
        } else {
            form.post(route(routeName, routeParams), {
                onSuccess: () => {
                    notification.success({
                        message: 'Success',
                        description: 'Domain created successfully!',
                    });
                    emit('success');
                    resetForm();
                },
                onError: (errors) => {
                    notification.error({
                        message: 'Error',
                        description: 'Please check the form for errors.',
                    });
                },
            });
        }
    } catch (error) {
        console.error('Form validation failed:', error);
    }
};

const handleCancel = () => {
    resetForm();
    emit('close');
};

const resetForm = () => {
    try {
        if (form && typeof form.reset === 'function') {
            form.reset();
        }
        if (form && typeof form.clearErrors === 'function') {
            form.clearErrors();
        }
    } catch (error) {
        console.warn('Error resetting form:', error);
    }
};

// Watch for visibility changes to reset form
const handleVisibleChange = (visible) => {
    if (!visible) {
        handleCancel();
    }
};
</script>

<template>
    <a-modal
        :visible="visible"
        :title="isEdit ? 'Edit Domain' : 'Create New Domain'"
        :confirm-loading="form.processing"
        @ok="handleSubmit"
        @cancel="handleCancel"
        width="600px"
    >
        <a-form
            ref="formRef"
            :model="form"
            :rules="rules"
            layout="vertical"
            @finish="handleSubmit"
        >
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <a-form-item
                    label="Domain Name"
                    name="name"
                    class="md:col-span-2"
                >
                    <a-input
                        v-model:value="form.name"
                        placeholder="Enter domain name (e.g., Rodnie Store)"
                        @input="updateSlug"
                    />
                </a-form-item>

                <a-form-item
                    label="Domain Slug"
                    name="name_slug"
                    class="md:col-span-2"
                >
                    <a-input
                        v-model:value="form.name_slug"
                        placeholder="rodnie-store"
                        addon-after=".techiko-pos.com"
                    />
                    <template #extra>
                        Auto-generated from name. Used in URLs and routing.
                    </template>
                </a-form-item>

                <a-form-item
                    label="Description"
                    name="description"
                    class="md:col-span-2"
                >
                    <a-textarea
                        v-model:value="form.description"
                        placeholder="Brief description of this domain..."
                        :rows="3"
                    />
                </a-form-item>

                <a-form-item
                    label="Country"
                    name="country_code"
                >
                    <a-select
                        v-model:value="form.country_code"
                        placeholder="Select Country"
                        :options="countryOptions"
                        allow-clear
                    />
                </a-form-item>

                <a-form-item
                    label="Currency"
                    name="currency_code"
                >
                    <a-select
                        v-model:value="form.currency_code"
                        placeholder="Select Currency"
                        :options="currencyOptions"
                        allow-clear
                    />
                </a-form-item>

                <a-form-item
                    label="Timezone"
                    name="timezone"
                    class="md:col-span-2"
                >
                    <a-select
                        v-model:value="form.timezone"
                        placeholder="Select Timezone"
                        :options="timezoneOptions"
                        allow-clear
                        show-search
                        :filter-option="filterOption"
                    />
                </a-form-item>

                <a-form-item
                    label="Date Format"
                    name="date_format"
                >
                    <a-select
                        v-model:value="form.date_format"
                        placeholder="Select Date Format"
                        :options="[
                            { label: 'YYYY-MM-DD', value: 'Y-m-d' },
                            { label: 'MM/DD/YYYY', value: 'm/d/Y' },
                            { label: 'DD/MM/YYYY', value: 'd/m/Y' },
                            { label: 'DD-MM-YYYY', value: 'd-m-Y' },
                        ]"
                        allow-clear
                    />
                </a-form-item>

                <a-form-item
                    label="Language"
                    name="language_code"
                >
                    <a-select
                        v-model:value="form.language_code"
                        placeholder="Select Language"
                        :options="[
                            { label: 'English', value: 'en' },
                            { label: 'Filipino', value: 'fil' },
                            { label: 'Japanese', value: 'ja' },
                            { label: 'Chinese', value: 'zh' },
                            { label: 'Spanish', value: 'es' },
                        ]"
                        allow-clear
                    />
                </a-form-item>

                <a-form-item
                    label="Time Format"
                    name="time_format"
                >
                    <a-select
                        v-model:value="form.time_format"
                        placeholder="Select Time Format"
                        :options="[
                            { label: '24 Hour (HH:MM:SS)', value: 'H:i:s' },
                            { label: '12 Hour (h:MM:SS A)', value: 'h:i:s A' },
                            { label: '24 Hour (HH:MM)', value: 'H:i' },
                            { label: '12 Hour (h:MM A)', value: 'h:i A' },
                        ]"
                        allow-clear
                    />
                </a-form-item>

                <a-form-item
                    name="is_active"
                    class="md:col-span-2"
                >
                    <a-checkbox v-model:checked="form.is_active">
                        Active Domain
                    </a-checkbox>
                    <template #extra>
                        Active domains are available for use and can be accessed by users.
                    </template>
                </a-form-item>
            </div>
        </a-form>
    </a-modal>
</template>
