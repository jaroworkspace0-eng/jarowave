<script setup lang="ts">
import FlaggedHouseholds from '../Shared/FlaggedHouseholds.vue';
</script>
<template>
    <FlaggedHouseholds allowed-role="admin" :can-clear="true" />
</template>
