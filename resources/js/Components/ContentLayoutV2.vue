<script setup>
const props = defineProps({
  title: {
    type: String,
    default: "Title",
  },
  filterMainClass: {
    type: String,
    default:
      "flex justify-between lg:flex-row xl:flex-row flex-col lg:items-center xl:items-center items-start mb-3 w-full",
  },
  filterClass: {
    type: String,
    default:
      "flex justify-end lg:space-x-3 xl:lg:space-x-3 space-x-1 lg:w-5/6 xl:w-5/6 w-full",
  },
});
</script>

<template>
  <div class="rounded-lg w-full flex gap-4">
    <div class="w-[70%] p-6 bg-white rounded-lg shadow">
      <div :class="filterMainClass">
        <h1 class="text-lg font-semibold text-nowrap">{{ title }}</h1>
        <div :class="filterClass">
          <slot name="filters" />
        </div>
      </div>
      <div>
        <slot name="activeFilters" />
      </div>
      <div>
        <slot name="table" />
      </div>
    </div>
    <div class="w-[30%] bg-white p-6 rounded-lg shadow">
      <slot name="right-side-content" />
    </div>
  </div>
</template>
