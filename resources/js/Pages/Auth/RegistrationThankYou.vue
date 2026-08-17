<script setup>
import { Head, Link } from '@inertiajs/vue3';
</script>

<template>
  <Head title="Thank You" />
  <div class="min-h-screen flex items-center justify-center bg-gray-50 px-6 py-12">
    <div class="max-w-xl w-full bg-white border rounded-xl shadow-sm p-8 text-center">
      <h1 class="text-2xl font-semibold text-gray-900 mb-2">Thank you for registering!</h1>
      <p class="text-gray-600 mb-6">
        We sent a verification link to your email. Open it to confirm your address—you’ll be signed in automatically. You can also use Sign in below after you’ve verified.
      </p>
      <div class="space-y-2 text-sm text-gray-500 mb-8">
        <p>If you don’t see the message, check spam or promotions. Until you verify, most app pages will redirect you to the verification screen when you sign in.</p>
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


