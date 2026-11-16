<script setup>
import { Link, usePage } from '@inertiajs/vue3';
const page = usePage();
defineProps({ section: { type: String, default: 'studio' } });
</script>
<template>
  <div class="app-shell">
    <aside class="sidebar">
      <Link href="/studio" class="brand" aria-label="Frame Studio home"><span class="brand-icon">f<span>•</span></span><span>frame<span class="brand-sub">STUDIO</span></span></Link>
      <div class="workspace-label">YOUR WORKSPACE</div>
      <nav aria-label="Main navigation">
        <Link href="/studio" :class="['nav-item', { active: section === 'studio' }]"><span aria-hidden="true">▧</span> Image studio <span class="nav-arrow">↗</span></Link>
        <Link v-if="page.props.auth.user.is_admin" href="/report" :class="['nav-item', { active: section === 'report' }]"><span aria-hidden="true">▥</span> Usage & profit</Link>
        <Link v-if="!page.props.auth.user.is_admin" href="/account" :class="['nav-item', { active: section === 'account' }]"><span aria-hidden="true">⚙</span> Account</Link>
        <Link href="/logout" method="post" as="button" class="nav-item compact-sign-out" aria-label="Sign out"><span aria-hidden="true">↪</span> Sign out</Link>
      </nav>
      <div class="sidebar-note"><span class="live-dot"></span> A little room to create<p>Powered by Pixelforge.<br>Simulated images, no real money.</p></div>
      <div class="account"><div class="avatar">{{ page.props.auth.user.name.slice(0,1) }}</div><div><strong>{{ page.props.auth.user.name }}</strong><span>Creative workspace</span></div><Link href="/logout" method="post" as="button" class="sign-out" aria-label="Sign out">↪</Link></div>
    </aside>
    <main class="main"><slot /></main>
  </div>
</template>
