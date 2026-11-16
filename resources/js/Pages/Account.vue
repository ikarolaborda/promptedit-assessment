<script setup>
import { Head } from '@inertiajs/vue3';
import Shell from '../Components/Shell.vue';
defineProps({ providers: Array, connected: Array, canConnect: Boolean, csrfToken: String, errors: { type: Object, default: () => ({}) } });
</script>
<template>
  <div>
    <Head title="Account" />
    <Shell section="account">
      <div class="topbar">WORKSPACE / ACCOUNT</div>
      <header class="page-heading"><div><span class="eyebrow">YOUR WAY IN</span><h1>A familiar way to sign in.</h1><p>Connect a provider while signed in to keep one private workspace.</p></div></header>
      <div class="report-content">
        <p v-if="$page.props.flash.message" class="reconcile-banner" role="status">{{ $page.props.flash.message }}</p>
        <div v-if="errors.oauth" class="error-box" role="alert">{{ errors.oauth }}</div>
        <section class="panel report-panel">
          <h2>Connected sign-in methods</h2>
          <p v-if="!canConnect" class="muted">The shared demo cannot connect personal identities. Create a private account first.</p>
          <p v-else-if="!providers.length" class="muted">Provider sign-in is not currently configured.</p>
          <div v-for="provider in providers" :key="provider" class="identity-row">
            <strong>{{ provider === 'google' ? 'Google' : 'Apple' }}</strong>
            <span v-if="connected.includes(provider)" class="provider-pill">Connected</span>
            <form v-else-if="canConnect" method="post" :action="`/account/connect/${provider}`">
              <input type="hidden" name="_token" :value="csrfToken" />
              <button class="secondary">Connect {{ provider === 'google' ? 'Google' : 'Apple' }}</button>
            </form>
          </div>
          <p class="fine">We request basic identity information only. Accounts are never merged based only on matching email addresses.</p>
        </section>
      </div>
    </Shell>
  </div>
</template>
