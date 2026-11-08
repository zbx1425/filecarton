<script setup>
const props = defineProps({
  name: { type: String, required: true },
  zh: { type: String, required: true },
  magnitude: { type: Number, required: true },
})
</script>

<template>
  <article class="star-card">
    <h2>{{ zh }}</h2>
    <p>{{ name }}</p>
    <p class="mag">V {{ magnitude.toFixed(2) }}</p>
  </article>
</template>
