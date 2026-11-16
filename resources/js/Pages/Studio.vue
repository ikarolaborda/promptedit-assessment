<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/vue3';
import Shell from '../Components/Shell.vue';
const props = defineProps({ history: Object, stats: Object });
const page = usePage();
const form = useForm({ prompt: '', size: '1024x1024', quality: 'medium', reference_images: [], client_request_id: crypto.randomUUID() });
const credit = useForm({ request_id: crypto.randomUUID() });
const references = ref('');
const showReferences = ref(false);
const quote = ref(null), quoteError = ref(''), quoting = ref(false);
const brokenImages = ref({});
const details = ref(null);
let timer, controller;
const display = (value, places = 6) => value == null ? '—' : '$' + Number(value).toFixed(places);
const humanDate = value => new Intl.DateTimeFormat('en', { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' }).format(new Date(value));
const insufficient = computed(() => quote.value && Number(props.stats.available) < Number(quote.value.maximum));
const canGenerate = computed(() => form.prompt.trim() && quote.value && !quoting.value && !form.processing && !insufficient.value && props.stats.active < 3);
usePoll(4000, { only: ['history', 'stats'] });
watch([() => form.prompt, () => form.size, () => form.quality, references], () => {
  clearTimeout(timer); controller?.abort(); quote.value = null; quoteError.value = '';
  form.reference_images = references.value.split('\n').map(x => x.trim()).filter(Boolean);
  form.client_request_id = crypto.randomUUID();
  if (!form.prompt.trim()) { quoting.value = false; return; }
  quoting.value = true;
  timer = setTimeout(loadQuote, 350);
});
async function loadQuote() {
  controller = new AbortController(); const currentController = controller;
  try {
    const token = document.cookie.split('; ').find(x => x.startsWith('XSRF-TOKEN='))?.slice(11);
    const response = await fetch('/quote', { method:'POST', credentials:'same-origin', signal:currentController.signal, headers: { 'Content-Type':'application/json', Accept:'application/json', 'X-XSRF-TOKEN': decodeURIComponent(token || '') }, body:JSON.stringify({ prompt:form.prompt, size:form.size, quality:form.quality, reference_images:form.reference_images }) });
    const data = await response.json();
    if (!response.ok) throw new Error(data.errors ? Object.values(data.errors).flat().join(' ') : data.message || 'Pricing is unavailable. Please try again.');
    if (currentController === controller) quote.value = data;
  } catch (error) { if (error.name !== 'AbortError' && currentController === controller) quoteError.value = error.message; }
  finally { if (currentController === controller) quoting.value = false; }
}
function generate() { if (canGenerate.value) form.post('/generations', { preserveScroll:true, onSuccess:() => { form.client_request_id = crypto.randomUUID(); } }); }
function addCredits() { credit.post('/credits', { preserveScroll:true, onSuccess:() => credit.request_id = crypto.randomUUID() }); }
function sample() { form.prompt = 'A quiet coastal house at golden hour, soft film grain, warm terracotta and sage tones [succeed]'; }
onBeforeUnmount(() => { clearTimeout(timer); controller?.abort(); });
</script>
<template>
  <Head title="Image studio" />
  <Shell>
    <header class="topbar"><span>WORKSPACE <span class="slash">/</span> IMAGE STUDIO</span><span class="provider-pill"><i class="live-dot"></i> Pixelforge</span></header>
    <div class="page-heading"><div><span class="eyebrow">A BLANK CANVAS, ENDLESS POSSIBILITIES</span><h1>What will you make today?</h1><p>Turn a small idea into something you can see.</p></div><div class="balance"><span>Available credits</span><strong>{{ display(stats.available, 4) }}</strong><button @click="addCredits" :disabled="credit.processing">+ Buy $5 demo credits</button><small>No real payment · {{ display(stats.reserved, 4) }} reserved</small></div></div>
    <div v-if="page.props.flash.message" class="flash" role="status">{{ page.props.flash.message }}</div>
    <div class="workspace-grid">
      <section class="composer panel" aria-labelledby="composer-heading">
        <div class="section-heading"><div><span class="eyebrow">01 / CREATE</span><h2 id="composer-heading">Bring it into focus.</h2></div><span class="tiny-frame" aria-hidden="true">✧</span></div>
        <form @submit.prevent="generate">
          <label class="label-row" for="prompt">Describe your image <span>{{ form.prompt.length }} / 4,000</span></label>
          <textarea id="prompt" v-model="form.prompt" maxlength="4000" rows="6" placeholder="A sunlit room, a mountain at dusk, a place that only exists in your head…" required></textarea>
          <button type="button" class="text-button sample" @click="sample">✧ Try a little inspiration</button>
          <fieldset><legend>Choose a frame</legend><div class="size-options"><button v-for="size in [{v:'1024x1024',label:'Square',ratio:'1:1',icon:'square'},{v:'1536x1024',label:'Landscape',ratio:'3:2',icon:'landscape'},{v:'1024x1536',label:'Portrait',ratio:'2:3',icon:'portrait'}]" :key="size.v" type="button" :aria-pressed="form.size===size.v" :class="['size-option', { selected: form.size===size.v }]" @click="form.size=size.v"><span :class="['shape',size.icon]"></span><strong>{{ size.label }}</strong><small>{{ size.ratio }}</small></button></div></fieldset>
          <label for="quality">Detail level<select id="quality" v-model="form.quality"><option value="low">Draft — quick ideas, lower cost</option><option value="medium">Balanced — a little more detail</option><option value="high">High — the finest finish</option></select></label>
          <button class="reference-toggle" type="button" :aria-expanded="showReferences" @click="showReferences=!showReferences"><span>+ Add reference images</span><span>{{ form.reference_images.length ? form.reference_images.length + ' / 4' : 'Optional' }}</span></button>
          <label v-if="showReferences" class="reference-field">Image URLs<textarea v-model="references" rows="3" placeholder="https://example.com/image.png&#10;One URL per line, up to four." /><span class="fine">References add input-image tokens to the price.</span></label>
          <div class="quote-box" aria-live="polite"><div class="quote-main"><span>Estimated cost</span><strong>{{ quoting ? 'Calculating…' : quote ? display(quote.estimate) : '—' }}</strong></div><template v-if="quote"><div class="quote-range">Expected range {{ display(quote.minimum) }} – {{ display(quote.maximum) }}</div><p>Includes a 25% markup. We reserve the estimated maximum; only the final token usage is charged. Failed generations are free.</p></template><p v-else>{{ quoteError || 'Add a prompt to see live pricing before you create.' }}</p><button v-if="quoteError" type="button" class="text-button" @click="loadQuote">Retry pricing</button></div>
          <p v-if="insufficient" class="error-text">Add demo credits to cover the estimated maximum.</p>
          <p v-if="stats.active >= 3" class="error-text">Three images are in progress. Your next canvas will be ready soon.</p>
          <div v-if="Object.keys(form.errors).length" class="error-box" role="alert"><p v-for="(error,key) in form.errors" :key="key">{{ error }}</p></div>
          <button class="primary full" :disabled="!canGenerate">{{ form.processing ? 'Joining the queue…' : 'Generate image' }} <span>↗</span></button><p class="fine center">A simulated creative workspace. No real charges.</p>
        </form>
      </section>
      <section class="history" aria-labelledby="history-heading">
        <div class="history-heading"><div><span class="eyebrow">02 / YOUR COLLECTION</span><h2 id="history-heading">Ideas, made visible.</h2></div><span class="count-pill">{{ stats.total }} {{ stats.total===1 ? 'image' : 'images' }}</span></div>
        <div v-if="!history.data.length" class="empty-state"><div class="empty-art" aria-hidden="true"><span></span><i></i></div><h3>Every collection starts with one idea.</h3><p>Describe something on the left.<br>Your first frame will find its home here.</p><button class="text-button" @click="sample">Start with an example →</button></div>
        <div v-else class="image-grid"><article v-for="g in history.data" :key="g.id" class="generation-card">
          <div class="image-wrap"><img v-if="g.image_url && !g.image_expired && !brokenImages[g.id]" :src="g.image_url" :alt="g.prompt" loading="lazy" @error="brokenImages[g.id]=true" /><div v-else :class="['image-placeholder', g.status]"><template v-if="g.status==='queued' || g.status==='processing'"><span class="spinner"></span><strong>{{ g.needs_attention ? 'Confirmation pending' : g.status==='queued' ? 'Your idea is in line.' : 'Finding its form…' }}</strong><small>{{ g.error_message || 'You can keep exploring. We will update this automatically.' }}</small></template><template v-else-if="g.status==='failed'"><span class="placeholder-symbol">↻</span><strong>This one didn’t come together.</strong><small>No charge. A different prompt might help.</small></template><template v-else><span class="placeholder-symbol">▧</span><strong>The image link has expired.</strong><small>Your generation and pricing record are still here.</small></template></div><span :class="['status-badge',g.status]">{{ g.status==='succeeded' ? 'Ready' : g.status==='failed' ? 'Not charged' : g.status }}</span></div>
          <div class="card-body"><p class="prompt-preview">{{ g.prompt }}</p><div class="card-meta"><span>{{ g.size }} · {{ g.quality }}</span><span>{{ humanDate(g.created_at) }}</span></div><div class="cost-row"><div><span>Estimated</span><strong>{{ display(g.estimate) }}</strong></div><div><span>{{ g.status==='failed' ? 'Charged' : 'Actual cost' }}</span><strong>{{ g.actual===null ? 'Pending' : display(g.actual) }}</strong></div><button class="detail-button" @click="details=details===g.id ? null : g.id" :aria-expanded="details===g.id">{{ details===g.id ? 'Less' : 'Details' }}</button></div><div v-if="details===g.id" class="cost-detail"><p>Exact estimate: ${{ g.estimate }}</p><p>Exact charge: {{ g.actual===null ? 'Pending completion' : '$'+g.actual }}</p><p v-if="g.usage">Text {{ g.usage.input_text_tokens }} · References {{ g.usage.input_image_tokens }} · Output {{ g.usage.output_tokens }} · Reasoning {{ g.usage.reasoning_tokens }} tokens</p><p v-if="g.error_message">{{ g.error_message }}</p></div></div>
        </article></div>
        <nav v-if="history.last_page > 1" class="pagination" aria-label="Generation pages"><Link v-if="history.prev_page_url" :href="history.prev_page_url" preserve-scroll>← Previous</Link><span>Page {{ history.current_page }} of {{ history.last_page }}</span><Link v-if="history.next_page_url" :href="history.next_page_url" preserve-scroll>Next →</Link></nav>
        <div class="collection-footer"><span class="live-dot"></span> Updates automatically <span>·</span> {{ display(stats.spent) }} total spend</div>
      </section>
    </div>
  </Shell>
</template>
