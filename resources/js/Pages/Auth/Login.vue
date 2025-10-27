<script setup>
import InputError from "@/Components/InputError.vue";
import { Head, Link, useForm } from "@inertiajs/vue3";
import { ref } from "vue";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";

defineProps({
    canResetPassword: {
        type: Boolean,
    },
    status: {
        type: String,
    },
});

const form = useForm({
    email: "",
    password: "",
    remember: false,
});

const spinning = ref(false);
const submit = () => {
    form.post(route("login"), {
        onFinish: () => {
            spinning.value = false;
            form.reset("password");
        },
        onStart: () => (spinning.value = true),
    });
};
</script>

<template>
    <Head title="Log in" />

    <div class="min-h-screen flex justify-center items-center bg-gray-50">
        <div
            class="flex max-h-[600px] max-w-[1000px] border rounded-xl overflow-hidden shadow-2xl bg-white"
        >
            <!-- Left Section - Branding -->

            <div
                class="hidden lg:flex lg:w-1/2 bg-gradient-to-br from-blue-600 to-teal-500 relative overflow-hidden"
                style="
                    clip-path: polygon(
                        0% 0%,
                        91% 0,
                        100% 50%,
                        93% 100%,
                        0% 100%
                    );
                "
            >
                <!-- Background Pattern -->
                <div class="absolute inset-0 bg-black opacity-10"></div>

                <!-- Content -->
                <div
                    class="relative z-10 flex flex-col justify-center px-12 text-white"
                >
                    <!-- Logo -->
                    <div class="flex items-start mb-8">
                        <div
                            class="w-14 h-14 bg-white rounded-lg flex items-center justify-center mr-2 shadow-lg"
                        >
                            <ApplicationLogo class="w-8 h-8" />
                        </div>
                        <div>
                            <div class="text-3xl font-bold text-white">
                                <span class="text-green-400">Tech</span>iko
                            </div>
                            <div class="text-blue-100 text-sm">
                                Point of Sale Solutions
                            </div>
                        </div>
                    </div>

                    <!-- Tagline -->
                    <p class="text-xl mb-8 text-blue-100">
                        Empowering businesses with smart and efficient POS
                        systems. Fast, secure, and built to scale with your
                        growth.
                    </p>

                    <!-- Features -->
                    <div class="space-y-6 mb-8">
                        <div class="flex items-center group">
                            <div
                                class="w-8 h-8 bg-green-400 rounded-full flex items-center justify-center mr-4 group-hover:scale-110 transition-transform duration-200"
                            >
                                <svg
                                    class="w-5 h-5 text-white"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                        clip-rule="evenodd"
                                    ></path>
                                </svg>
                            </div>
                            <span class="text-lg font-medium"
                                >Seamless transaction management</span
                            >
                        </div>
                        <div class="flex items-center group">
                            <div
                                class="w-8 h-8 bg-green-400 rounded-full flex items-center justify-center mr-4 group-hover:scale-110 transition-transform duration-200"
                            >
                                <svg
                                    class="w-5 h-5 text-white"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                        clip-rule="evenodd"
                                    ></path>
                                </svg>
                            </div>
                            <span class="text-lg font-medium"
                                >Real-time sales & inventory insights</span
                            >
                        </div>
                        <div class="flex items-center group">
                            <div
                                class="w-8 h-8 bg-green-400 rounded-full flex items-center justify-center mr-4 group-hover:scale-110 transition-transform duration-200"
                            >
                                <svg
                                    class="w-5 h-5 text-white"
                                    fill="currentColor"
                                    viewBox="0 0 20 20"
                                >
                                    <path
                                        fill-rule="evenodd"
                                        d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
                                        clip-rule="evenodd"
                                    ></path>
                                </svg>
                            </div>
                            <span class="text-lg font-medium"
                                >24/7 dedicated customer support</span
                            >
                        </div>
                    </div>

                    <!-- Trust Statement -->
                    <p class="text-blue-100 text-sm">
                        Join thousands of retailers and businesses trusting
                        Techicko for their POS and business automation needs.
                    </p>
                </div>
            </div>

            <!-- Right Section - Login Form -->
            <div
                class="w-full lg:w-1/2 flex items-center justify-center px-8 py-12"
            >
                <div class="w-full max-w-md">
                    <!-- Mobile Logo (visible on small screens) -->
                    <div class="lg:hidden text-center mb-8">
                        <div
                            class="w-16 h-16 bg-gradient-to-br from-blue-600 to-teal-500 rounded-xl flex items-center justify-center mx-auto mb-4 shadow-lg"
                        >
                            <ApplicationLogo class="w-10 h-10 text-white" />
                        </div>
                        <h1 class="text-2xl font-bold text-gray-900">
                            Techicko
                        </h1>
                        <p class="text-gray-600 text-sm">Digital Solutions</p>
                    </div>

                    <!-- Form Header -->
                    <div class="text-center mb-8">
                        <h2 class="text-3xl font-bold text-gray-900 mb-2">
                            Welcome Back
                        </h2>
                        <p class="text-gray-600">
                            Sign in to your Techicko account to continue
                        </p>
                    </div>

                    <!-- Status Message -->
                    <div
                        v-if="status"
                        class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg"
                    >
                        <p class="text-green-800 text-sm">{{ status }}</p>
                    </div>

                    <!-- Login Form -->
                    <form @submit.prevent="submit" class="space-y-6">
                        <!-- Email Field -->
                        <div>
                            <label
                                for="email"
                                class="block text-sm font-medium text-gray-700 mb-2"
                            >
                                Email Address
                            </label>
                            <input
                                id="email"
                                type="email"
                                v-model="form.email"
                                required
                                autofocus
                                autocomplete="username"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 hover:border-gray-400"
                                placeholder="you@company.com"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.email"
                            />
                        </div>

                        <!-- Password Field -->
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label
                                    for="password"
                                    class="block text-sm font-medium text-gray-700"
                                >
                                    Password
                                </label>
                                <Link
                                    v-if="canResetPassword"
                                    :href="route('password.request')"
                                    class="text-sm text-blue-600 hover:text-blue-500 font-medium"
                                >
                                    Forgot Password?
                                </Link>
                            </div>
                            <input
                                id="password"
                                type="password"
                                v-model="form.password"
                                required
                                autocomplete="current-password"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all duration-200 hover:border-gray-400"
                                placeholder="Enter your password"
                            />
                            <InputError
                                class="mt-2"
                                :message="form.errors.password"
                            />
                        </div>

                        <!-- Remember Me -->
                        <div class="flex items-center">
                            <input
                                id="remember"
                                type="checkbox"
                                v-model="form.remember"
                                class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                            />
                            <label
                                for="remember"
                                class="ml-2 block text-sm text-gray-700"
                            >
                                Keep me signed in
                            </label>
                        </div>

                        <!-- Sign In Button -->
                        <button
                            type="submit"
                            :disabled="spinning"
                            class="w-full bg-gradient-to-r from-green-600 to-teal-500 text-white font-semibold py-3 px-4 rounded-lg hover:from-blue-700 hover:to-teal-600 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-all duration-200 disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                            <span
                                v-if="spinning"
                                class="flex items-center justify-center"
                            >
                                <svg
                                    class="animate-spin -ml-1 mr-3 h-5 w-5 text-white"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    ></circle>
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                    ></path>
                                </svg>
                                Signing in...
                            </span>
                            <span v-else>Sign In</span>
                        </button>
                    </form>

                    <!-- Register Link -->
                    <div class="mt-6 text-center">
                        <p class="text-gray-600">
                            Don't have an account?
                            <Link
                                :href="route('register')"
                                class="text-blue-600 hover:text-blue-500 font-medium"
                            >
                                Create one
                            </Link>
                        </p>
                    </div>

                    <!-- Terms -->
                    <div class="mt-8 text-center">
                        <p class="text-xs text-gray-500">
                            By signing in, you agree to Techicko's
                            <a
                                href="#"
                                class="text-blue-600 hover:text-blue-500"
                                >Terms of Service</a
                            >
                            and
                            <a
                                href="#"
                                class="text-blue-600 hover:text-blue-500"
                                >Privacy Policy</a
                            >
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
