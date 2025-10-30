<script setup>
import InputError from "@/Components/InputError.vue";
import InputLabel from "@/Components/unused/InputLabel.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";

const form = useForm({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
    organization: "",
    country_code: "PH",
    timezone: "Asia/Manila",
});

const spinning = ref(false);
const submit = () => {
    form.post(route("register"), {
        onFinish: () => {
            spinning.value = false;
            form.reset("password", "password_confirmation");
        },
        onStart: () => (spinning.value = true),
    });
};
</script>

<template>
    <Head title="Register" />

    <div
        class="min-h-screen w-full bg-gray-50 flex items-center justify-center py-12 px-6"
    >
        <main class="w-full flex items-center justify-center">
            <div class="w-full max-w-[600px]">
                <!-- Header -->
                <div class="mb-6">
                    <h2 class="text-3xl font-bold text-gray-900">
                        Create Account
                    </h2>
                    <p class="text-gray-600">
                        Your organization will be reviewed before access
                    </p>
                </div>

                <!-- Info banner -->
                <div
                    class="mb-6 p-3 rounded-lg bg-blue-50 border border-blue-100 text-sm text-blue-700"
                >
                    Create your organization. We will review and approve before
                    access.
                </div>

                <!-- Form card -->
                <form
                    @submit.prevent="submit"
                    class="bg-white rounded-xl border shadow-sm p-6"
                >
                    <!-- Organization -->
                    <div class="grid grid-cols-1 gap-4 mb-5">
                        <div>
                            <label
                                for="organization"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Organization Name
                            </label>
                            <input
                                id="organization"
                                type="text"
                                v-model="form.organization"
                                required
                                autocomplete="organization"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                placeholder="Your company name"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.organization"
                            />
                        </div>
                    </div>

                    <!-- Two-column row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                        <div>
                            <label
                                for="name"
                                class="block text-sm font-medium text-gray-700 mb-1"
                                >Full Name</label
                            >
                            <input
                                id="name"
                                type="text"
                                v-model="form.name"
                                required
                                autocomplete="name"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                placeholder="Jane Doe"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.name"
                            />
                        </div>
                        <div>
                            <label
                                for="email"
                                class="block text-sm font-medium text-gray-700 mb-1"
                                >Email Address</label
                            >
                            <input
                                id="email"
                                type="email"
                                v-model="form.email"
                                required
                                autocomplete="username"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                placeholder="you@company.com"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.email"
                            />
                        </div>
                    </div>

                    <!-- Two-column row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                        <div>
                            <label
                                for="country_code"
                                class="block text-sm font-medium text-gray-700 mb-1"
                                >Country</label
                            >
                            <select
                                id="country_code"
                                v-model="form.country_code"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition bg-white"
                            >
                                <option value="PH">Philippines (PH)</option>
                                <option value="US">United States (US)</option>
                                <option value="SG">Singapore (SG)</option>
                                <option value="AE">
                                    United Arab Emirates (AE)
                                </option>
                            </select>
                            <InputError
                                class="mt-2"
                                :message="form.errors.country_code"
                            />
                        </div>
                        <div>
                            <label
                                for="timezone"
                                class="block text-sm font-medium text-gray-700 mb-1"
                                >Timezone</label
                            >
                            <select
                                id="timezone"
                                v-model="form.timezone"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition bg-white"
                            >
                                <option value="Asia/Manila">Asia/Manila</option>
                                <option value="UTC">UTC</option>
                                <option value="America/New_York">
                                    America/New_York
                                </option>
                                <option value="Asia/Singapore">
                                    Asia/Singapore
                                </option>
                            </select>
                            <InputError
                                class="mt-2"
                                :message="form.errors.timezone"
                            />
                        </div>
                    </div>

                    <!-- Two-column row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label
                                for="password"
                                class="block text-sm font-medium text-gray-700 mb-1"
                                >Password</label
                            >
                            <input
                                id="password"
                                type="password"
                                v-model="form.password"
                                required
                                autocomplete="new-password"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                placeholder="Create a strong password"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.password"
                            />
                        </div>
                        <div>
                            <label
                                for="password_confirmation"
                                class="block text-sm font-medium text-gray-700 mb-1"
                            >
                                Confirm Password
                            </label>
                            <input
                                id="password_confirmation"
                                type="password"
                                v-model="form.password_confirmation"
                                required
                                autocomplete="new-password"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition"
                                placeholder="Re-enter your password"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.password_confirmation"
                            />
                        </div>
                    </div>

                    <!-- Submit -->
                    <div class="mt-6">
                        <button
                            type="submit"
                            :disabled="spinning"
                            class="w-full bg-gradient-to-r from-green-600 to-teal-500 text-white font-semibold py-3 px-4 rounded-lg hover:from-blue-700 hover:to-teal-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span
                                v-if="spinning"
                                class="flex items-center justify-center gap-2"
                            >
                                <svg
                                    class="animate-spin h-5 w-5 text-white"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    />
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                                    />
                                </svg>
                                Creating account...
                            </span>
                            <span v-else>Register</span>
                        </button>
                    </div>

                    <!-- Footer -->
                    <div class="mt-6 text-center">
                        <p class="text-gray-600">
                            Already registered?
                            <Link
                                :href="route('login')"
                                class="text-blue-600 hover:text-blue-500 font-medium"
                                >Sign in</Link
                            >
                        </p>
                    </div>
                </form>
            </div>
        </main>
    </div>
</template>
