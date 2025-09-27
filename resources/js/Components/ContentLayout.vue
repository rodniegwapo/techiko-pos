<script setup>
const props = defineProps({
  title: {
    type: String,
    default: null,
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
  <div>
    <div
      class="bg-white rounded-lg px-6 py-6 lg:w-full md:w-full sm:w-[750px] lg:overflow-auto md:overflow-auto sm:overflow-scroll"
    >
      <div :class="filterMainClass">
        <h1 class="text-lg font-semibold text-nowrap"  :class="{'visible': title, 'invisible':!title}">{{ title }}</h1>
        <div :class="filterClass">
          <slot name="filters" />
        </div>
      </div>
      <div>
        <slot name="activeFilters" />
      </div>
      <div class="-mx-6 table-without-padding">
        <slot name="table" />
      </div>
    </div>
  </div>
</template>
