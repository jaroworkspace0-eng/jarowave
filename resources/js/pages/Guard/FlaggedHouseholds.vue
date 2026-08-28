<script setup lang="ts">
import FlaggedHouseholds from '../Shared/FlaggedHouseholds.vue';
</script>
<template>
    <FlaggedHouseholds allowed-role="gate_guard" :can-clear="false" />
</template>
