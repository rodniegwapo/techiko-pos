<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
  <Head title="Thank You" />
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-6 py-12">
    <div class="max-w-xl w-full bg-white border rounded-xl shadow-sm p-8 text-center">
      <h1 class="text-2xl font-semibold text-gray-900 mb-2">Thank you for registering!</h1>
      <p class="text-gray-600 mb-6">
        Your organization was submitted for review. We’ll notify you by email once it’s approved.
      </p>
      <div class="space-y-2 text-sm text-gray-500 mb-8">
        <p>If you didn’t receive any emails, please check your spam folder.</p>
        <p>You may safely close this window now.</p>
      </div>
      <Link
        :href="route('login')"
        class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-blue-600 text-white hover:bg-blue-700 transition"
      >
        Back to Sign In
      </Link>
    </div>
  </div>
</template>


