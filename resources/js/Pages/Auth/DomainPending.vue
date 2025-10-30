<script setup>
import { Head, usePage } from "@inertiajs/vue3";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

const page = usePage();
const domain = page.props.domain || page.props?.domain?.data || page.props?.domain;
</script>

<template>
  <Head title="Awaiting Approval" />
  <AuthenticatedLayout>
    <div class="max-w-3xl mx-auto py-16 px-6 text-center">
      <h1 class="text-2xl font-semibold mb-4">Organization Awaiting Approval</h1>
      <p class="text-gray-600 mb-6">
        {{ domain?.name || 'Your organization' }} is currently pending approval.
        You will receive an update once an administrator reviews your request.
      </p>
      <div class="text-sm text-gray-500">
        If you believe this is a mistake, please contact support or your administrator.
      </div>
    </div>
  </AuthenticatedLayout>
  
</template>


