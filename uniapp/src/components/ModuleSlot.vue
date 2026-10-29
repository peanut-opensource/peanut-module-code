<!-- Generated from installed Module contributions. -->
<template>
  <view>
    <Slot0 v-if="name === 'home'" />
    <Slot1 v-if="name === 'oauth-login'" />
    <Slot2 v-if="name === 'oauth-binding'" />
  </view>
</template>
<script setup lang="ts">
  import Slot0 from '@/modules/peanut-article/components/HomeContent.vue';
  import Slot1 from '@/modules/peanut-oauth/components/OAuthLoginButtons.vue';
  import Slot2 from '@/modules/peanut-oauth/components/OAuthBindButton.vue';
  defineProps<{ name: string }>();
</script>
