<script setup lang="ts">
import FlaggedHouseholds from '../Shared/FlaggedHouseholds.vue';
</script>
<template>
    <FlaggedHouseholds allowed-role="estate_billing" :can-clear="true" />
</template>
