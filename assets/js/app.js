const $=(s,r=document)=>r.querySelector(s), $$=(s,r=document)=>[...r.querySelectorAll(s)];
const sidebar=$('#sidebar'),backdrop=$('#mobileBackdrop'),sidebarToggle=$('#sidebarToggle');
function setSidebarState(open){
  if(!sidebar)return;
  sidebar.classList.toggle('open',!!open);
  backdrop?.classList.toggle('show',!!open);
  sidebarToggle?.setAttribute('aria-expanded',open?'true':'false');
  sidebarToggle?.setAttribute('aria-label',open?'Close navigation':'Open navigation');
  document.body.classList.toggle('nav-open',!!open);
}
sidebarToggle?.addEventListener('click',()=>setSidebarState(!sidebar?.classList.contains('open')));
backdrop?.addEventListener('click',()=>setSidebarState(false));
$$('.sidebar .nav-link').forEach(a=>a.addEventListener('click',()=>{if(innerWidth<992)setSidebarState(false)}));
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&sidebar?.classList.contains('open'))setSidebarState(false)});
window.addEventListener('resize',()=>{if(innerWidth>=992)setSidebarState(false)});
$$('[data-confirm]').forEach(el=>el.addEventListener('click',e=>{if(!confirm(el.dataset.confirm||'Are you sure?'))e.preventDefault()}));
setTimeout(()=>$$('.auto-hide').forEach(x=>x.remove()),4500);
const themeBtn=$('#themeToggle');if(localStorage.getItem('sportsync-theme')==='dark')document.body.classList.add('theme-dark');
function syncThemeIcon(){const i=$('i',themeBtn);if(!i)return;i.className=document.body.classList.contains('theme-dark')?'fa-regular fa-sun':'fa-regular fa-moon'}syncThemeIcon();
themeBtn?.addEventListener('click',()=>{document.body.classList.toggle('theme-dark');localStorage.setItem('sportsync-theme',document.body.classList.contains('theme-dark')?'dark':'light');syncThemeIcon()});
$$('[data-table-search]').forEach(input=>input.addEventListener('input',()=>{const table=$(input.dataset.tableSearch);if(!table)return;const q=input.value.toLowerCase().trim();$$('tbody tr',table).forEach(row=>row.style.display=row.innerText.toLowerCase().includes(q)?'':'none')}));
$$('[data-password-toggle]').forEach(btn=>btn.addEventListener('click',()=>{const input=$(btn.dataset.passwordToggle);if(!input)return;input.type=input.type==='password'?'text':'password';$('i',btn)?.classList.toggle('fa-eye');$('i',btn)?.classList.toggle('fa-eye-slash')}));
$$('.password-strength-input').forEach(input=>input.addEventListener('input',()=>{const meter=input.closest('form')?.querySelector('.password-meter');if(!meter)return;const v=input.value;let score=0;if(v.length>=10)score++;if(/[A-Z]/.test(v)&&/[a-z]/.test(v))score++;if(/\d/.test(v))score++;meter.className='password-meter '+(score<=1?'weak':score===2?'medium':'strong')}));
$$('form').forEach(form=>form.addEventListener('submit',()=>{const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn&&!btn.dataset.noLoading){btn.dataset.original=btn.innerHTML;btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm"></span> Working…'}}));

// Calendar-based event planner
const findBtn=$('#findAvailability'), plannerStart=$('#plannerStart'), plannerEnd=$('#plannerEnd'), plannerPeople=$('#plannerPeople'), venueResults=$('#venueResults'), equipmentResults=$('#equipmentResults'), selectedVenue=$('#selectedVenueId'), submitPlan=$('#submitPlan');
const autoMatchBtn=$('#autoMatchBtn'), autoMatchField=$('#autoMatchField'), autoMatchStatus=$('#autoMatchStatus');
const plannerDate=$('#plannerDate'), plannerStartTime=$('#plannerStartTime'), plannerEndTime=$('#plannerEndTime');
let plannerMap,plannerMarker,calendarDate=new Date();
function escapeHtml(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c]))}
function dateInputValue(d){return d.toISOString().slice(0,10)}
function syncPlannerDateTime(){
  if(!plannerDate||!plannerStartTime||!plannerEndTime)return;
  plannerStart.value=plannerDate.value&&plannerStartTime.value?`${plannerDate.value}T${plannerStartTime.value}`:'';
  plannerEnd.value=plannerDate.value&&plannerEndTime.value?`${plannerDate.value}T${plannerEndTime.value}`:'';
  const txt=$('#selectedDateText'); if(txt)txt.textContent=plannerDate.value?new Date(plannerDate.value+'T00:00:00').toLocaleDateString(undefined,{weekday:'long',month:'long',day:'numeric',year:'numeric'}):'Choose a date on the calendar.';
  updatePlannerSummary();
}
function updatePlannerSummary(){
  const s=$('#summarySchedule');if(s)s.textContent=plannerStart?.value&&plannerEnd?.value?new Date(plannerStart.value).toLocaleString()+' – '+new Date(plannerEnd.value).toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}):'Not selected';
  let count=0;$$('#equipmentResults input[type="number"]').forEach(x=>count+=Number(x.value||0));const e=$('#summaryEquipment');if(e)e.textContent=count?count+' item(s)':'None';
  if($('#travelResult')&&plannerStart?.value){const t=new Date(plannerStart.value),travel=Number($('#travelMins')?.value||0),prep=Number($('#prepMins')?.value||0);const depart=new Date(t-(travel+prep)*60000);$('#travelResult').textContent='Leave by '+depart.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});}
}
function renderPlannerCalendar(){
  const root=$('#eventCalendar'),title=$('#calendarTitle');if(!root)return;
  const y=calendarDate.getFullYear(),m=calendarDate.getMonth();title.textContent=new Date(y,m,1).toLocaleDateString(undefined,{month:'long',year:'numeric'});
  root.innerHTML='';const first=new Date(y,m,1).getDay(),days=new Date(y,m+1,0).getDate(),today=new Date();today.setHours(0,0,0,0);
  for(let i=0;i<first;i++){const blank=document.createElement('span');blank.className='calendar-blank';root.appendChild(blank)}
  for(let d=1;d<=days;d++){const dt=new Date(y,m,d),btn=document.createElement('button');btn.type='button';btn.className='calendar-day';btn.textContent=d;if(dt<today)btn.disabled=true;if(dt.getDate()===today.getDate()&&dt.getMonth()===today.getMonth()&&dt.getFullYear()===today.getFullYear())btn.classList.add('today');if(plannerDate?.value===dateInputValue(dt))btn.classList.add('selected');btn.addEventListener('click',()=>{plannerDate.value=dateInputValue(dt);$$('.calendar-day').forEach(x=>x.classList.remove('selected'));btn.classList.add('selected');syncPlannerDateTime();plannerStartTime?.focus()});root.appendChild(btn)}
}
$('#calendarPrev')?.addEventListener('click',()=>{calendarDate=new Date(calendarDate.getFullYear(),calendarDate.getMonth()-1,1);renderPlannerCalendar()});
$('#calendarNext')?.addEventListener('click',()=>{calendarDate=new Date(calendarDate.getFullYear(),calendarDate.getMonth()+1,1);renderPlannerCalendar()});
plannerDate?.addEventListener('change',()=>{if(plannerDate.value){const d=new Date(plannerDate.value+'T00:00:00');calendarDate=new Date(d.getFullYear(),d.getMonth(),1);renderPlannerCalendar()}syncPlannerDateTime()});
plannerStartTime?.addEventListener('change',syncPlannerDateTime);plannerEndTime?.addEventListener('change',syncPlannerDateTime);equipmentResults?.addEventListener('input',updatePlannerSummary);$('#travelMins')?.addEventListener('input',updatePlannerSummary);$('#prepMins')?.addEventListener('input',updatePlannerSummary);
if($('#eventCalendar')){const now=new Date();plannerDate.min=dateInputValue(now);plannerDate.value=dateInputValue(now);plannerStartTime.value='08:00';plannerEndTime.value='10:00';renderPlannerCalendar();syncPlannerDateTime()}

// Personal travel estimate: when a venue is chosen, look up the driving time
// from the user's saved home address (api/travel_time.php -> Nominatim+OSRM)
// and pre-fill the Travel minutes in the preparation reminder.
async function fillTravelEstimate(venueId){
  const input=$('#travelMins'),src=$('#travelSource');if(!input||!venueId)return;
  if(input.dataset.venue===String(venueId))return;
  input.dataset.venue=String(venueId);
  src.textContent='Checking route from your saved address…';
  try{
    const r=await fetch('api/travel_time.php?venue_id='+encodeURIComponent(venueId),{cache:'no-store'});
    const d=await r.json();
    if(!d.ok){src.textContent=d.error||'';return}
    input.value=d.minutes;updatePlannerSummary();
    src.textContent='≈ '+d.km+' km · '+d.minutes+' min drive from '+(d.origin||'your address')+' to '+d.venue;
  }catch(e){src.textContent='Travel estimate unavailable right now.'}
}
// Automatic venue matching: selects the best conflict-free venue for the
// chosen schedule. The availability API returns venues ordered by capacity
// (smallest first) after running the full conflict rule set — buffer time,
// opening hours, capacity and venue overlap — so the first entry is always a
// safe pick, and large venues stay free for genuinely large events.
function selectVenueCard(v){
  const el=$$('.venue-option').find(x=>$('input',x)&&$('input',x).value===String(v.id));
  if(!el)return;
  $$('.venue-option').forEach(x=>x.classList.remove('selected'));el.classList.add('selected');
  selectedVenue.value=v.id;$('#summaryVenue').textContent=v.name;submitPlan.disabled=false;
  fillTravelEstimate(v.id);
  if(v.latitude&&v.longitude){$('#plannerMap').classList.add('active');if(!plannerMap)plannerMap=L.map('plannerMap').setView([+v.latitude,+v.longitude],15);else plannerMap.setView([+v.latitude,+v.longitude],15);if(!plannerMap._tiles){L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(plannerMap);plannerMap._tiles=true}if(plannerMarker)plannerMap.removeLayer(plannerMarker);plannerMarker=L.marker([+v.latitude,+v.longitude]).addTo(plannerMap).bindPopup(`<strong>${escapeHtml(v.name)}</strong><br>${escapeHtml(v.address||'Tupi, South Cotabato')}`).openPopup();setTimeout(()=>plannerMap.invalidateSize(),120)}
}
// Any manual venue click cancels the auto-match flag so the server knows the
// user chose the venue themselves.
venueResults?.addEventListener('click',()=>{if(autoMatchField)autoMatchField.value='0';if(autoMatchStatus)autoMatchStatus.textContent=''});
autoMatchBtn?.addEventListener('click',async()=>{
  syncPlannerDateTime();
  if(!plannerStart.value||!plannerEnd.value||new Date(plannerEnd.value)<=new Date(plannerStart.value)){alert('Choose a valid event date, start time, and end time first.');return}
  autoMatchBtn.disabled=true;autoMatchBtn.innerHTML='<span class="spinner-border spinner-border-sm"></span> Matching…';
  if(autoMatchStatus)autoMatchStatus.textContent='';
  try{
    const qs=new URLSearchParams({start_at:plannerStart.value,end_at:plannerEnd.value,people:plannerPeople.value||1});
    const r=await fetch('api/availability.php?'+qs);const d=await r.json();
    if(!d.ok)throw new Error(d.error||'Unable to check availability.');
    if(!d.venues.length){
      const msg=d.rules&&d.rules.blocked?d.rules.error:'No venue is free for that schedule and group size. Try another date, time, or participant count.';
      venueResults.innerHTML=`<div class="empty-inline"><i class="fa-solid fa-circle-info"></i><span>${escapeHtml(msg)}</span></div>`;
      if(autoMatchStatus)autoMatchStatus.textContent='';
      return;
    }
    d.venues.forEach(v=>{const card=document.createElement('label');card.className='venue-option';card.innerHTML=`<input type="radio" name="venue_choice" value="${v.id}"><div class="venue-option-icon"><i class="fa-solid fa-location-dot"></i></div><div><h4>${escapeHtml(v.name)}</h4><p>${escapeHtml(v.address||'Tupi, South Cotabato')}</p><span><i class="fa-solid fa-users"></i> Capacity ${v.capacity} · ${escapeHtml(v.facilities||'General facilities')}</span></div>`;card.addEventListener('click',()=>{selectVenueCard(v)});venueResults.appendChild(card)});
    d.equipment.forEach(eq=>{const row=$(`[data-equipment-id="${eq.id}"]`);if(!row)return;const label=$('.available-label',row),input=$('input',row);label.textContent=eq.available_quantity+' available for this schedule';input.max=Math.max(0,eq.available_quantity);input.disabled=eq.available_quantity<=0;row.classList.toggle('unavailable',eq.available_quantity<=0)});
    updatePlannerSummary();
    // Smallest venue that fits the group and has zero schedule conflicts.
    // Venues with capacity 0 (unspecified) are only used as a last resort.
    const best=d.venues.find(v=>+v.capacity>0)||d.venues[0];
    selectVenueCard(best);
    if(autoMatchField)autoMatchField.value='1';
    if(autoMatchStatus)autoMatchStatus.innerHTML=`<i class="fa-solid fa-circle-check"></i>Matched <strong>${escapeHtml(best.name)}</strong> — conflict-free for your schedule.`;
  }catch(e){if(autoMatchStatus)autoMatchStatus.textContent=e.message||'Auto-match failed. Try again.'}
  finally{autoMatchBtn.disabled=false;autoMatchBtn.innerHTML='<i class="fa-solid fa-wand-magic-sparkles"></i>Match venue for me'}
});
findBtn?.addEventListener('click',async()=>{
  syncPlannerDateTime();if(!plannerStart.value||!plannerEnd.value||new Date(plannerEnd.value)<=new Date(plannerStart.value)){alert('Choose a valid event date, start time, and end time.');return}
  findBtn.disabled=true;findBtn.innerHTML='<span class="spinner-border spinner-border-sm"></span> Checking availability…';
  try{const qs=new URLSearchParams({start_at:plannerStart.value,end_at:plannerEnd.value,people:plannerPeople.value||1});const r=await fetch('api/availability.php?'+qs);const d=await r.json();if(!d.ok)throw new Error(d.error||'Unable to check availability.');selectedVenue.value='';submitPlan.disabled=true;$('#summaryVenue').textContent='Not selected';venueResults.innerHTML=d.venues.length?'':'<div class="empty-inline"><i class="fa-solid fa-circle-xmark"></i><span>No venue is available for this schedule and capacity. Try another date or time.</span></div>';
    const ruleHint=d.venues.length? (d.rules&&d.rules.blocked?()=>`<div class="empty-inline"><i class="fa-solid fa-triangle-exclamation"></i><span>${escapeHtml(d.rules.error)}</span></div>`:'') : null;
    if(d.venues.length===0){
      const msg=d.rules&&d.rules.blocked? d.rules.error : 'No venue is available for this schedule and capacity. Try another date or time.';
      venueResults.innerHTML=`<div class="empty-inline"><i class="fa-solid fa-circle-info"></i><span>${escapeHtml(msg)}</span></div>`;
      submitPlan.disabled=true;$('#summaryVenue').textContent='Not selected';selectedVenue.value='';
    } else {
      d.venues.forEach(v=>{const el=document.createElement('label');el.className='venue-option';el.innerHTML=`<input type="radio" name="venue_choice" value="${v.id}"><div class="venue-option-icon"><i class="fa-solid fa-location-dot"></i></div><div><h4>${escapeHtml(v.name)}</h4><p>${escapeHtml(v.address||'Tupi, South Cotabato')}</p><span><i class="fa-solid fa-users"></i> Capacity ${v.capacity} · ${escapeHtml(v.facilities||'General facilities')}</span></div>`;el.addEventListener('click',()=>{$$('.venue-option').forEach(x=>x.classList.remove('selected'));el.classList.add('selected');selectedVenue.value=v.id;$('#summaryVenue').textContent=v.name;submitPlan.disabled=false;fillTravelEstimate(v.id);if(v.latitude&&v.longitude){$('#plannerMap').classList.add('active');if(!plannerMap)plannerMap=L.map('plannerMap').setView([+v.latitude,+v.longitude],15);else plannerMap.setView([+v.latitude,+v.longitude],15);if(!plannerMap._tiles){L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(plannerMap);plannerMap._tiles=true}if(plannerMarker)plannerMap.removeLayer(plannerMarker);plannerMarker=L.marker([+v.latitude,+v.longitude]).addTo(plannerMap).bindPopup(`<strong>${escapeHtml(v.name)}</strong><br>${escapeHtml(v.address||'Tupi, South Cotabato')}`).openPopup();setTimeout(()=>plannerMap.invalidateSize(),120)}});venueResults.appendChild(el)});
    }
    d.equipment.forEach(eq=>{const row=$(`[data-equipment-id="${eq.id}"]`);if(!row)return;const label=$('.available-label',row),input=$('input',row);label.textContent=eq.available_quantity+' available for this schedule';input.max=Math.max(0,eq.available_quantity);input.disabled=eq.available_quantity<=0;row.classList.toggle('unavailable',eq.available_quantity<=0)});updatePlannerSummary();
  }catch(e){venueResults.innerHTML='<div class="empty-inline"><i class="fa-solid fa-triangle-exclamation"></i><span>'+escapeHtml(e.message)+'</span></div>'}finally{findBtn.disabled=false;findBtn.innerHTML='<i class="fa-solid fa-magnifying-glass"></i>Check availability'}
});

// Tupi, South Cotabato venue map
if($('#venueMap')){const cards=$$('.venue-directory-card');const valid=cards.map((card,i)=>({card,id:i,lat:parseFloat(card.dataset.lat),lng:parseFloat(card.dataset.lng)})).filter(v=>Number.isFinite(v.lat)&&Number.isFinite(v.lng));const tupi=[6.3348,124.9526];const map=L.map('venueMap').setView(tupi,14);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map);const markers=[];valid.forEach(v=>{const title=$('h3',v.card)?.textContent||'Sporty Ni Migo Venue',address=$('p',v.card)?.textContent||'Tupi, South Cotabato';const marker=L.marker([v.lat,v.lng]).addTo(map).bindPopup(`<strong>${escapeHtml(title)}</strong><br>${escapeHtml(address)}<br><a target="_blank" rel="noopener" href="https://www.google.com/maps/search/?api=1&query=${v.lat},${v.lng}">Open directions</a>`);markers.push(marker);$('.show-venue-map',v.card)?.addEventListener('click',()=>{map.setView([v.lat,v.lng],16);marker.openPopup()})});if(valid.length){const group=L.featureGroup(markers);map.fitBounds(group.getBounds().pad(.25),{maxZoom:15})}setTimeout(()=>map.invalidateSize(),200)}

// Venue pin drop helpers (Add venue + Edit venue modals)
function createVenuePinMap(mapElId, pinLatId, pinLngId, clearBtnId, focusLat, focusLng, onReady){
  if(!mapElId) return null;
  const mapEl=$(mapElId), pinLat=$(pinLatId), pinLng=$(pinLngId), clearBtn=$(clearBtnId);
  if(!mapEl) return null;
  const map=L.map(mapElId).setView(focusLat&&focusLng?[+focusLat,+focusLng]:[6.3348,124.9526],14);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map);
  let marker=null;
  function setPin(lat,lng,open){ if(marker)marker.remove(); marker=L.marker([lat,lng]).addTo(map); if(open)marker.openPopup(); if(pinLat)pinLat.value=lat.toFixed(7); if(pinLng)pinLng.value=lng.toFixed(7); }
  map.on('click',e=>setPin(e.latlng.lat,e.latlng.lng,true));
  if(clearBtn) clearBtn.addEventListener('click',()=>{ if(marker){marker.remove();marker=null} if(pinLat)pinLat.value=''; if(pinLng)pinLng.value=''; });
  if(onReady) onReady(map,setPin);
  setTimeout(()=>map.invalidateSize(),120);
  const modalEl=mapEl?.closest('.modal');
  modalEl?.addEventListener('shown.bs.modal',()=>setTimeout(()=>map.invalidateSize(),80));
  return {map,setPin};
}

// Add venue modal: start with no pin; user clicks map to drop one.
if($('#addVenueMap')){
  createVenuePinMap('addVenueMap','pinLat','pinLng','clearPin',null,null,(map)=>{
    $('#saveVenuePin')?.addEventListener('click',()=>{ if(!pinLat.value||!pinLng.value){ alert('Drop a pin on the map first.'); return } $('#venuePinModal').querySelector('form').submit(); });
    $('#useCurrentLocation')?.addEventListener('click',async()=>{
      try{ const pos=await navigator.geolocation.getCurrentPosition({enableHighAccuracy:true,timeout:10000}); $(`#addVenueMap`).dispatchEvent(new CustomEvent('pinset',{detail:{lat:pos.coords.latitude,lng:pos.coords.longitude}})); }
      catch(e){ alert('Location access was denied or is unavailable. You can place the pin manually on the map.'); }
    });
  });
}

// Edit venue modal: load existing pin; allow moving it.
$$('[id^="editVenueMap"]').forEach(mapEl=>{
  const id=mapEl.id.replace('editVenueMap','');
  const latInput=$(`#editLat${id}`), lngInput=$(`#editLng${id}`);
  const focusLat=latInput?latInput.value:null, focusLng=lngInput?lngInput.value:null;
  const wrapper=createVenuePinMap(mapEl.id,'editLat'+id,'editLng'+id,'clearEditPin'+id,focusLat,focusLng,(map,setPin)=>{
    if(focusLat&&focusLng&&Number.isFinite(+focusLat)&&Number.isFinite(+focusLng)) setPin(+focusLat,+focusLng,false);
    $('#useEditLocation'+id)?.addEventListener('click',async()=>{
      try{ const pos=await navigator.geolocation.getCurrentPosition({enableHighAccuracy:true,timeout:10000}); map.setView([pos.coords.latitude,pos.coords.longitude]); setPin(pos.coords.latitude,pos.coords.longitude,true); }
      catch(e){ alert('Location access was denied or is unavailable.'); }
    });
  });
});

// Support programmatic pin placement from the add-venue modal (geolocation or caller)
document.addEventListener('pinset',e=>{
  if(e.target&&e.target.id==='addVenueMap'&&$('#pinLat')&&$('#pinLng')){
    const lat=e.detail.lat,lng=e.detail.lng;
    const map=$('#addVenueMap')?.__pinMap; if(map&&map.setPin) map.setPin(lat,lng,true); else {
      $('#pinLat').value=lat.toFixed(7); $('#pinLng').value=lng.toFixed(7);
      if(!window._addVenuePinFallenBack){ window._addVenuePinFallenBack=true; setTimeout(()=>{ const map=L.map('addVenueMap'); map.setView([lat,lng]); L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap'}).addTo(map); L.marker([lat,lng]).addTo(map).openPopup(); setTimeout(()=>map.invalidateSize(),120); window._addVenuePinFallenBack=false; },120); }
    }
  }
});


// Shop search and sorting
const shopGrid=$('#shopGrid'),shopSearch=$('#shopSearch'),shopSort=$('#shopSort');function refreshShop(){if(!shopGrid)return;let cards=$$('.shop-card',shopGrid),q=(shopSearch?.value||'').trim().toLowerCase();cards.forEach(c=>c.hidden=q&&!c.dataset.name.includes(q));if(shopSort?.value&&shopSort.value!=='default'){const mode=shopSort.value;cards.sort((a,b)=>mode==='price-asc'?+a.dataset.price-+b.dataset.price:mode==='price-desc'?+b.dataset.price-+a.dataset.price:+b.dataset.stock-+a.dataset.stock).forEach(c=>shopGrid.appendChild(c))}}shopSearch?.addEventListener('input',refreshShop);shopSort?.addEventListener('change',refreshShop);

// Live Tupi weather widget used on every page
async function loadGlobalWeather(){const widgets=$$('#globalWeather, #dashboardWeather');if(!widgets.length)return;try{const r=await fetch('api/current_weather.php',{cache:'no-store'}),d=await r.json();if(!d.ok)throw new Error(d.error||'Weather unavailable');const temp=Math.round(d.temperature),rain=Math.round(d.rain_probability||0),wind=Math.round(d.wind_speed||0),label=d.condition||'Current conditions';if($('#globalWeatherTemp'))$('#globalWeatherTemp').textContent=temp+'°C';if($('#globalWeatherText'))$('#globalWeatherText').textContent=label;if($('#globalWeatherRain'))$('#globalWeatherRain').textContent=rain+'%';if($('#dashWeatherTemp'))$('#dashWeatherTemp').textContent=temp+'°C';if($('#dashWeatherText'))$('#dashWeatherText').textContent=label;if($('#dashRain'))$('#dashRain').textContent=rain+'%';if($('#dashWind'))$('#dashWind').textContent=wind;const ps=$('#plannerWeatherSummary');if(ps)ps.textContent=`${temp}°C · ${label} · ${rain}% rain`;}catch(e){if($('#globalWeatherText'))$('#globalWeatherText').textContent='Weather unavailable';if($('#dashWeatherText'))$('#dashWeatherText').textContent='Weather service unavailable';}}
loadGlobalWeather();setInterval(loadGlobalWeather,10*60*1000);

// Messenger-style communication with integrated voice recorder
const chatThread=$('#chatThread');if(chatThread)chatThread.scrollTop=chatThread.scrollHeight;
$('#contactSearch')?.addEventListener('input',e=>{const q=e.target.value.toLowerCase();$$('.contact-item').forEach(x=>x.hidden=!x.innerText.toLowerCase().includes(q))});
const voicePanel=$('#voiceRecorderInline'),voiceOpenBtns=[$('#voiceQuickBtn'),$('#composerVoiceBtn')].filter(Boolean),voiceCancel=$('#voiceCancelBtn'),voiceRecord=$('#voiceRecordBtn'),voiceStop=$('#voiceStopBtn'),voiceSend=$('#voiceSendBtn'),voicePreview=$('#voicePreview'),voiceTimer=$('#voiceTimer'),voiceFeedback=$('#voiceFeedback');let voiceRecorder,voiceChunks=[],voiceBlob,voiceSecs=0,voiceInt;
voiceOpenBtns.forEach(b=>b.addEventListener('click',()=>voicePanel?.classList.toggle('d-none')));voiceCancel?.addEventListener('click',()=>{voicePanel.classList.add('d-none');voicePreview?.classList.add('d-none');voiceSend?.classList.add('d-none');if(voiceRecorder&&voiceRecorder.state!=='inactive')voiceRecorder.stop()});
voiceRecord?.addEventListener('click',async()=>{try{const stream=await navigator.mediaDevices.getUserMedia({audio:true});voiceRecorder=new MediaRecorder(stream);voiceChunks=[];voiceRecorder.ondataavailable=e=>voiceChunks.push(e.data);voiceRecorder.onstop=()=>{voiceBlob=new Blob(voiceChunks,{type:voiceRecorder.mimeType});voicePreview.src=URL.createObjectURL(voiceBlob);voicePreview.classList.remove('d-none');voiceSend.classList.remove('d-none');stream.getTracks().forEach(t=>t.stop())};voiceRecorder.start();voiceRecord.disabled=true;voiceStop.disabled=false;voiceSecs=0;voiceTimer.textContent='00:00';voiceInt=setInterval(()=>{voiceSecs++;voiceTimer.textContent=String(Math.floor(voiceSecs/60)).padStart(2,'0')+':'+String(voiceSecs%60).padStart(2,'0')},1000)}catch(e){voiceFeedback.innerHTML='<div class="alert alert-danger py-2">Microphone permission is required. Voice recording also requires HTTPS on deployed sites.</div>'}});
voiceStop?.addEventListener('click',()=>{if(voiceRecorder&&voiceRecorder.state!=='inactive')voiceRecorder.stop();clearInterval(voiceInt);voiceRecord.disabled=false;voiceStop.disabled=true});
voiceSend?.addEventListener('click',async()=>{const receiver=$('#chatComposer input[name="receiver_id"]')?.value;if(!receiver||!voiceBlob)return;const fd=new FormData();fd.append('audio',voiceBlob,'voice.webm');fd.append('receiver_id',receiver);fd.append('duration',voiceSecs);fd.append('csrf',window.SPORTSYNC?.csrf||'');voiceSend.disabled=true;voiceSend.textContent='Sending...';try{const r=await fetch('voice_upload.php',{method:'POST',body:fd}),d=await r.json();if(!d.ok)throw new Error(d.error||'Unable to send voice message.');location.reload()}catch(e){voiceFeedback.innerHTML='<div class="alert alert-danger py-2">'+escapeHtml(e.message)+'</div>';voiceSend.disabled=false;voiceSend.textContent='Send voice'}});

// Weather
$$('.weather-card').forEach(async card=>{const lat=card.dataset.lat,lon=card.dataset.lon,out=$('.weather-result',card),eventTime=card.dataset.eventTime;if(!lat||!lon){out.innerHTML='<div class="weather-warning">Venue coordinates are not configured, so live weather cannot be loaded.</div>';return}try{const start=new Date(eventTime);const date=start.toISOString().slice(0,10);const r=await fetch(`https://api.open-meteo.com/v1/forecast?latitude=${lat}&longitude=${lon}&current=temperature_2m,wind_speed_10m,precipitation&hourly=temperature_2m,precipitation_probability,wind_speed_10m&timezone=auto&forecast_days=7`);const d=await r.json();let idx=d.hourly?.time?.findIndex(x=>Math.abs(new Date(x)-start)<31*60*1000)??-1;if(idx<0)idx=0;const temp=d.hourly?.temperature_2m?.[idx]??d.current?.temperature_2m??'-',rain=d.hourly?.precipitation_probability?.[idx]??0,wind=d.hourly?.wind_speed_10m?.[idx]??d.current?.wind_speed_10m??0;const bad=rain>=70||wind>=35;out.innerHTML=`<div class="weather-metrics"><div class="weather-metric"><strong>${temp}°C</strong><span>Temperature</span></div><div class="weather-metric"><strong>${rain}%</strong><span>Rain probability</span></div><div class="weather-metric"><strong>${wind} km/h</strong><span>Wind</span></div></div><div class="${bad?'weather-warning':'weather-good'}"><i class="fa-solid ${bad?'fa-triangle-exclamation':'fa-circle-check'}"></i> ${bad?'Weather may affect this event. Consider rescheduling or moving indoors.':'Conditions currently look acceptable. Continue monitoring before the event.'}</div>`}catch(e){out.innerHTML='<div class="weather-warning">Weather service is temporarily unavailable.</div>'}});

// Community post link fields
$('#postType')?.addEventListener('change',e=>{$('#eventLinkWrap')?.classList.toggle('d-none',e.target.value!=='event');$('#tournamentLinkWrap')?.classList.toggle('d-none',e.target.value!=='tournament')});
// Tournament view
$$('[data-tour-view]').forEach(btn=>btn.addEventListener('click',()=>{$$('[data-tour-view]').forEach(x=>x.classList.remove('active'));btn.classList.add('active');$$('.tour-view').forEach(x=>x.classList.add('d-none'));$('#tour-'+btn.dataset.tourView)?.classList.remove('d-none')}));

// Sporty Ni Migo UX Refinement v5
(() => {
  // Make responsive tables understandable when they turn into mobile cards.
  document.querySelectorAll('.modern-table').forEach((table) => {
    const headers = [...table.querySelectorAll('thead th')].map((th) => th.textContent.trim());
    table.querySelectorAll('tbody tr').forEach((row) => {
      [...row.children].forEach((cell, index) => {
        if (!cell.dataset.label) cell.dataset.label = headers[index] || 'Details';
      });
    });
  });

  // Scroll the first invalid form control into view instead of leaving users guessing.
  document.querySelectorAll('form').forEach((form) => {
    form.addEventListener('invalid', (event) => {
      event.preventDefault();
      const field = event.target;
      field.classList.add('is-invalid');
      field.scrollIntoView({ behavior: 'smooth', block: 'center' });
      setTimeout(() => field.focus({ preventScroll: true }), 200);
    }, true);
    form.addEventListener('input', (event) => event.target.classList?.remove('is-invalid'));
  });

  // Keep the selected planner venue visible after a tap on small screens.
  document.getElementById('venueResults')?.addEventListener('change', (event) => {
    if (event.target.matches('input[type="radio"][name="venue_choice"]') && window.innerWidth < 768) {
      event.target.closest('.venue-option')?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
  });

  // Prevent accidental double taps on links styled as action buttons while still allowing navigation.
  // Only plain navigation links; dropdown/offcanvas/modal toggles must stay clickable.
  document.querySelectorAll('a.btn:not([data-bs-toggle])').forEach((link) => {
    link.addEventListener('click', () => link.classList.add('is-navigating'), { once: true });
  });

})();
