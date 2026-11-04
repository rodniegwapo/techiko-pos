<script setup>
import InputError from '@/Components/InputError.vue';
import InputLabel from '@/Components/unused/InputLabel.vue';
import PrimaryButton from '@/Components/PrimaryButton.vue';
import TextInput from '@/Components/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

/** Change my PIN: the PIN you clock in and out with, and confirm actions with on the POS. Only you know it. */
defineProps({
    hasPin: { type: Boolean, default: false },
});

const form = useForm({
    current_password: '',
    pin_code: '',
    pin_code_confirmation: '',
});

const digitsOnly = (key) => {
    form[key] = String(form[key] || '').replace(/\D/g, '').slice(0, 6);
};

const updatePin = () => {
    form.put(route('profile.pin.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => form.reset('current_password', 'pin_code', 'pin_code_confirmation'),
    });
};
</script>

<template>
    <section data-testid="profile-pin">
        <header>
            <h2 class="text-lg font-medium text-gray-900">{{ hasPin ? 'Change my PIN' : 'Set my PIN' }}</h2>

            <p class="mt-1 text-sm text-gray-600">
                Your PIN is how you clock in and out on the POS. Change it so only you know it.
                <template v-if="!hasPin"> You don't have one yet.</template>
            </p>
        </header>

        <form @submit.prevent="updatePin" class="mt-6 space-y-6">
            <div>
                <InputLabel for="pin_current_password" value="Current Password" />
                <TextInput
                    id="pin_current_password"
                    v-model="form.current_password"
                    type="password"
                    class="mt-1 block w-full"
                    autocomplete="current-password"
                    data-testid="profile-pin-password"
                />
                <InputError :message="form.errors.current_password" class="mt-2" />
            </div>

            <div>
                <InputLabel for="pin_code" value="New PIN (4 to 6 digits)" />
                <TextInput
                    id="pin_code"
                    v-model="form.pin_code"
                    type="password"
                    inputmode="numeric"
                    maxlength="6"
                    class="mt-1 block w-full"
                    autocomplete="off"
                    data-testid="profile-pin-new"
                    @input="digitsOnly('pin_code')"
                />
                <InputError :message="form.errors.pin_code" class="mt-2" />
            </div>

            <div>
                <InputLabel for="pin_code_confirmation" value="Confirm PIN" />
                <TextInput
                    id="pin_code_confirmation"
                    v-model="form.pin_code_confirmation"
                    type="password"
                    inputmode="numeric"
                    maxlength="6"
                    class="mt-1 block w-full"
                    autocomplete="off"
                    data-testid="profile-pin-confirm"
                    @input="digitsOnly('pin_code_confirmation')"
                />
                <InputError :message="form.errors.pin_code_confirmation" class="mt-2" />
            </div>

            <div class="flex items-center gap-4">
                <PrimaryButton html-type="submit" :loading="form.processing" data-testid="profile-pin-save">Save PIN</PrimaryButton>

                <Transition
                    enter-active-class="transition ease-in-out"
                    enter-from-class="opacity-0"
                    leave-active-class="transition ease-in-out"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-sm text-gray-600" data-testid="profile-pin-saved">Saved.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
