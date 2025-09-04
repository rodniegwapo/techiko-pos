<script setup>
import { Head } from "@inertiajs/vue3";
import ContentHeader from "@/Components/ContentHeader.vue";
import { ref } from "vue";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import ContentLayout from "@/Components/ContentLayout.vue";
</script>

<template>
  <AuthenticatedLayout>
    <Head title="Categories" />
    <ContentHeader class="mb-8" title="Categories" />

    <ContentLayout title="Categories">
      <template #filters>
        <refresh-button :loading="spinning" @click="fetchGroups" />
        <a-input-search
          v-model:value="search"
          placeholder="Input search text"
          style="width: 100%"
          class="min-w-[100px] max-w-[300px]"
        />
        <a-button
          @click="showCreateModal"
          type="primary"
          class="bg-white border border-green-500 text-green-500 ml-3"
        >
          <template #icon>
            <PlusSquareOutlined />
          </template>
          Create Category
        </a-button>
      </template>
    </ContentLayout>
  </AuthenticatedLayout>
</template>