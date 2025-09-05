<script setup>
 defineProps({
   loading: {
     type: Boolean,
     default: false,
   },
 });
</script>

<template>
  <a-button
    :loading="loading"
    type="primary"
    class="bg-green-700 border border-green-700 hover:bg-green-500 hover:border-green-500"
    ><slot
  /></a-button>
</template>
