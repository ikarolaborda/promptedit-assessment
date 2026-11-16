<script setup>
import { Head } from '@inertiajs/vue3';
</script>
<template>
  <main class="privacy-page">
    <Head title="Privacy and data use" />
    <a href="/login" class="text-button">← Back to sign in</a>
    <h1>Privacy and data use</h1>
    <p>Frame Studio is a software assessment operated by Ikaro Laborda. Images and credit purchases are simulated. Please use sample prompts only.</p>
    <h2>Account information</h2>
    <p>Email registration stores your name, email and a hashed password. Google and Apple sign-in request your basic profile and email; we store a provider identifier to recognize your account. We do not retain provider access or refresh tokens or request access to your mail, files or contacts.</p>
    <h2>Your workspace</h2>
    <p>Your private workspace stores prompts, reference URLs, generation status and simulated credit records. Generation requests are sent to Pixelforge to produce simulated results. The shared demo is public to other reviewers; anything entered there can be seen by them. Usage reports show user identifiers rather than names or email addresses.</p>
    <h2>Cookies and storage</h2>
    <p>Essential cookies maintain your session, prevent forged requests and verify provider sign-in callbacks. Short-lived login cookies expire after ten minutes. Data is stored in the assessment database hosted on Microsoft Azure in North Europe. The application does not add advertising or analytics trackers.</p>
    <h2>Contact and removal</h2>
    <p>Contact <a href="mailto:iclaborda@aerolambda.tech">iclaborda@aerolambda.tech</a> to request removal of your assessment account and related data. This demonstration may be retired after the recruitment assessment.</p>
    <p class="fine">Last updated: 16 November 2026.</p>
  </main>
</template>
