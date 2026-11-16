<script setup>
import { ref } from 'vue';
// Provider artwork: developers.google.com/identity/branding-guidelines and appleid.cdn-apple.com/appleid/button/logo.
import googleLogo from '../../images/google-signin.png';
import appleLogo from '../../images/apple-signin.png';
import { Head, useForm } from '@inertiajs/vue3';
defineProps({ demoEnabled: Boolean, registrationEnabled: Boolean, providers: { type: Array, default: () => [] }, errors: { type: Object, default: () => ({}) } });
const register = ref(false);
const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });
const demo = useForm({});
function submit() { form.post(register.value ? '/register' : '/login', { onFinish: () => form.reset('password', 'password_confirmation') }); }
</script>
<template>
  <Head title="Make room for your next idea" />
  <div class="auth-layout">
    <section class="auth-story">
      <div class="brand"><span class="brand-icon">f<span>•</span></span><span>frame<span class="brand-sub">STUDIO</span></span></div>
      <div class="auth-copy"><div class="eyebrow light">FROM A THOUGHT TO A FRAME</div><h1>Your next idea<br>starts here.</h1><p>A quiet space to explore, experiment, and make something worth keeping.</p></div>
      <div class="abstract-art" aria-hidden="true"><div class="art-sun"></div><div class="art-hill one"></div><div class="art-hill two"></div><span>001 / A NEW PERSPECTIVE</span></div>
      <div class="auth-foot">Clear pricing. Thoughtful tools. Room to explore.</div>
    </section>
    <section class="auth-panel">
      <div class="auth-card"><span class="eyebrow">WELCOME TO YOUR WORKSPACE</span><h2>{{ register ? 'Make yourself at home.' : 'Good to see you.' }}</h2><p class="muted">{{ register ? 'Create an account with $5 in free demo credits.' : 'Sign in to pick up where your imagination left off.' }}</p>
        <div v-if="errors.oauth" class="error-box" role="alert">{{ errors.oauth }}</div>
        <div v-if="providers.length" class="social-buttons">
          <a v-for="provider in providers" :key="provider" :href="`/auth/${provider}/redirect`" :class="['social-button', provider]"><span class="social-logo" aria-hidden="true"><img :src="provider === 'google' ? googleLogo : appleLogo" alt="" /></span><span>Sign in with {{ provider === 'google' ? 'Google' : 'Apple' }}</span></a>
          <p class="fine center">or use your email and password</p>
        </div>
        <form @submit.prevent="submit" class="auth-form">
          <label v-if="register">Your name<input v-model="form.name" autocomplete="name" required maxlength="100" /></label>
          <label>Email address<input v-model="form.email" type="email" autocomplete="email" placeholder="you@example.com" required /></label>
          <label>Password<input v-model="form.password" type="password" :autocomplete="register ? 'new-password' : 'current-password'" :minlength="register ? 12 : undefined" required /></label>
          <label v-if="register">Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required /></label>
          <div v-if="Object.keys(form.errors).length" class="error-box" role="alert"><p v-for="(error, key) in form.errors" :key="key">{{ error }}</p></div>
          <button class="primary" :disabled="form.processing">{{ form.processing ? 'One moment…' : register ? 'Create my workspace' : 'Sign in' }} <span>→</span></button>
        </form>
        <p v-if="registrationEnabled" class="auth-switch">{{ register ? 'Already have an account?' : 'New around here?' }} <button @click="register = !register; form.clearErrors()">{{ register ? 'Sign in' : 'Create an account' }}</button></p>
        <template v-if="demoEnabled"><div class="or"><span>or take a look around</span></div><button class="secondary full" :disabled="demo.processing" @click="demo.post('/demo')">Explore the demo <span>↗</span></button><p class="fine center">Shared demo workspace. Use sample prompts only.<br>All credits and generations are simulated.</p></template>
        <p class="fine center"><a href="/privacy">Privacy and data use</a></p>
      </div>
    </section>
  </div>
</template>
