const bodycareProducts = [
  { id: 'cleanser', name: 'منظف لطيف للبشرة', category: 'face', description: 'تنظيف يومي لطيف ضمن روتين العناية.', image: 'https://images.unsplash.com/photo-1556228578-0d85b1a4d571?auto=format&fit=crop&w=700&q=80', base: 130, badge: 'يومي' },
  { id: 'body_oil', name: 'زيت عناية للجسم', category: 'body', description: 'زيت تجميلي للاستخدام الخارجي بعد الاستحمام.', image: 'https://images.unsplash.com/photo-1601049541289-9b1b7bbbfe19?auto=format&fit=crop&w=700&q=80', base: 180, badge: 'الجسم' },
  { id: 'lotion', name: 'لوشن مرطب للجسم', category: 'body', description: 'ترطيب يومي بملمس خفيف.', image: 'https://images.unsplash.com/photo-1608248543803-ba4f8c70ae0b?auto=format&fit=crop&w=700&q=80', base: 160, badge: 'ترطيب' },
  { id: 'soap', name: 'صابون نباتي', category: 'daily', description: 'قطعة عناية يومية للجسم.', image: 'https://images.unsplash.com/photo-1600857544200-b2f666a9a2ec?auto=format&fit=crop&w=700&q=80', base: 70, badge: 'أساسي' },
  { id: 'sunscreen', name: 'واقي شمس يومي', category: 'face', description: 'منتج تجميلي؛ اتبعي إرشادات العبوة.', image: 'https://images.unsplash.com/photo-1556229010-6c3f2c9ca5f8?auto=format&fit=crop&w=700&q=80', base: 200, badge: 'نهاري' },
  { id: 'lip_balm', name: 'مرطب شفاه', category: 'daily', description: 'أساس صغير للعناية اليومية.', image: 'https://images.unsplash.com/photo-1586495777744-4413f21062fa?auto=format&fit=crop&w=700&q=80', base: 60, badge: 'أساسي' },
  { id: 'scrub', name: 'مقشر للجسم', category: 'body', description: 'تقشير تجميلي للجسم حسب إرشادات المنتج.', image: 'https://images.unsplash.com/photo-1570194065650-d99fb4bedf0a?auto=format&fit=crop&w=700&q=80', base: 140, badge: 'عناية' },
  { id: 'hand_cream', name: 'كريم عناية لليدين', category: 'daily', description: 'عناية يومية لليدين.', image: 'https://images.unsplash.com/photo-1601612628452-9e99ced43524?auto=format&fit=crop&w=700&q=80', base: 90, badge: 'يومي' }
];
let bodycareEnabled = false;
let bodycareOwner = false;
let bodycarePricesReady = false;
let markupPercent = 0;
let discountPercent = 0;
let activeFilter = 'all';
let bodycareCart = JSON.parse(localStorage.getItem('virginia_east_cart') || '{}');
const bodycareSession = JSON.parse(localStorage.getItem('egga_session') || 'null');
const bodycareEsc = value => String(value ?? '').replace(/[&<>"']/g, char => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[char]));
const bodycareMoney = value => new Intl.NumberFormat('ar-EG', { maximumFractionDigits: 2 }).format(value) + ' ج.م';

function retailPrice(product) { return Math.round(product.base * (1 + markupPercent / 100) * 100) / 100; }
function currentPrice(product) { return Math.round(retailPrice(product) * (1 - discountPercent / 100) * 100) / 100; }
function renderBodycare() {
  const term = document.getElementById('search').value.trim().toLowerCase();
  const visible = bodycareProducts.filter(product => {
    const inCategory = activeFilter === 'all' || product.category === activeFilter || (activeFilter === 'offers' && discountPercent > 0);
    return inCategory && (!term || `${product.name} ${product.description}`.toLowerCase().includes(term));
  });
  document.getElementById('heading').textContent = activeFilter === 'offers' ? 'عروض فيرجينيا الشرق' : activeFilter === 'all' ? 'مختارات فيرجينيا الشرق' : activeFilter;
  document.getElementById('countLabel').textContent = `${visible.length} منتجات`;
  document.getElementById('products').innerHTML = visible.map(product => {
    const price = currentPrice(product);
    const offer = discountPercent > 0;
    return `<article class="product"><div class="photo"><img src="${bodycareEsc(product.image)}" alt="${bodycareEsc(product.name)}" loading="lazy" onerror="this.style.display='none'"><span class="tag ${offer?'sale':''}">${offer?`خصم ${discountPercent}%`:bodycareEsc(product.badge)}</span></div><div class="details"><span class="kind">${bodycareEsc(product.categoryLabel||product.category)}</span><h3 class="name">${bodycareEsc(product.name)}</h3><p class="description">${bodycareEsc(product.description)}</p><div class="buyrow"><span class="price">${bodycareMoney(price)}${offer?`<span class="old">${bodycareMoney(retailPrice(product))}</span>`:''}${bodycarePricesReady?'':'<small> مبدئي</small>'}</span><button class="add" ${product.stock<=0?'disabled':''} onclick="addBodycareItem('${product.id}')" aria-label="أضيفي ${bodycareEsc(product.name)} للسلة">${product.stock<=0?'×':'+'}</button></div><small>${product.stock>0?'متاح: '+product.stock:'غير متوفر'}</small></div></article>`;
  }).join('') || '<p class="description">لا توجد منتجات في هذا التصنيف</p>';
}
function persistBodycareCart() { localStorage.setItem('virginia_east_cart', JSON.stringify(bodycareCart)); renderBodycareCart(); }
function addBodycareItem(id) { const product=bodycareProducts.find(item=>item.id===id);if(!product||bodycareCart[id]>=product.stock)return toastBodycare('الكمية المتوفرة لا تكفي');bodycareCart[id] = (bodycareCart[id] || 0) + 1; persistBodycareCart(); toastBodycare('أضيف المنتج إلى السلة'); }
function changeBodycareQty(id, amount) { const product=bodycareProducts.find(item=>item.id===id);bodycareCart[id] = (bodycareCart[id] || 0) + amount; if (bodycareCart[id] < 1) delete bodycareCart[id];else if(product&&bodycareCart[id]>product.stock)bodycareCart[id]=product.stock; persistBodycareCart(); }
function removeBodycareItem(id) { delete bodycareCart[id]; persistBodycareCart(); }
function renderBodycareCart() {
  const entries = Object.entries(bodycareCart).filter(([id, qty]) => bodycareProducts.some(product => product.id === id) && Number.isInteger(qty) && qty > 0).map(([id,qty])=>[id,Math.min(qty,bodycareProducts.find(product=>product.id===id).stock)]).filter(([,qty])=>qty>0);
  bodycareCart = Object.fromEntries(entries);
  document.getElementById('count').textContent = entries.reduce((sum, [, qty]) => sum + qty, 0);
  document.getElementById('lines').innerHTML = entries.length ? entries.map(([id, qty]) => {
    const product = bodycareProducts.find(item => item.id === id);
    return `<div class="line"><strong>${bodycareEsc(product.name)}</strong><div class="line-foot"><span>${bodycareMoney(currentPrice(product) * qty)}</span><span class="qty"><button onclick="changeBodycareQty('${id}',-1)" aria-label="تقليل">−</button>${qty}<button onclick="changeBodycareQty('${id}',1)" aria-label="زيادة">+</button><button class="remove" onclick="removeBodycareItem('${id}')">حذف</button></span></div></div>`;
  }).join('') : '<p class="description">السلة فارغة</p>';
  document.getElementById('total').textContent = bodycareMoney(entries.reduce((sum, [id, qty]) => sum + currentPrice(bodycareProducts.find(item => item.id === id)) * qty, 0));
  document.getElementById('checkout').disabled = entries.length === 0;
}
function openBasket() { document.getElementById('basket').classList.add('open'); document.getElementById('shade').classList.add('open'); document.getElementById('basket').setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
function closeBasket() { document.getElementById('basket').classList.remove('open'); document.getElementById('shade').classList.remove('open'); document.getElementById('basket').setAttribute('aria-hidden','true'); document.body.style.overflow=''; }
function showOrderForm() { if (!Object.keys(bodycareCart).length) return; if (!bodycarePricesReady) return toastBodycare('الأسعار لم يعتمدها المالك بعد'); document.getElementById('orderForm').hidden = false; document.getElementById('buyerName').focus({preventScroll:true}); }
async function trackBodycareOrder(event){event.preventDefault();const node=document.getElementById('trackingResult');node.textContent='جارٍ البحث...';try{const response=await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'store_order_track',order_code:document.getElementById('trackCode').value,phone:document.getElementById('trackPhone').value})});const result=await response.json();if(!result.ok){node.textContent=result.msg;return;}const labels={new:'تم استلام الطلب',confirmed:'تم تأكيد الطلب',processing:'قيد التجهيز',shipped:'تم الشحن',fulfilled:'تم التسليم',cancelled:'ملغي'};node.innerHTML=`<b>${bodycareEsc(result.order.order_code)} · ${labels[result.order.status]||bodycareEsc(result.order.status)}</b><br>${result.order.events.map(item=>`${labels[item.new_status]||bodycareEsc(item.new_status)} · ${bodycareEsc(item.created_at)}`).join('<br>')}`;}catch(error){node.textContent='تعذر الاتصال، حاولي مرة أخرى';}}
async function submitBodycareOrder(event) {
  event.preventDefault();
  const button = document.getElementById('submitButton'); button.disabled = true; button.textContent = 'جارٍ تسجيل الطلب...';
  try {
    const response = await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'bodycare_store_order',token:bodycareSession&&bodycareSession.token||'',name:document.getElementById('buyerName').value,phone:document.getElementById('buyerPhone').value,gov:document.getElementById('buyerGov').value,address:document.getElementById('buyerAddress').value,note:document.getElementById('buyerNote').value,website:document.getElementById('website').value,items:Object.entries(bodycareCart).map(([id,quantity])=>({id,quantity}))})});
    const result = await response.json(); if (!result.ok) return toastBodycare(result.msg);
    bodycareCart = {}; persistBodycareCart(); document.getElementById('orderForm').hidden = true;
    const done = document.getElementById('done'); done.hidden = false; done.textContent = `تم تسجيل الطلب. رقم المتابعة ${result.order_code}، الإجمالي ${bodycareMoney(result.total)}. سيتواصل فريق المتجر لتأكيد التوصيل والدفع.`;
    document.getElementById('orderForm').reset();
  } catch (error) { toastBodycare('تعذر الاتصال بالمتجر، حاولي مجددًا'); }
  finally { button.disabled = false; button.textContent = 'تأكيد الطلب'; }
}
function toastBodycare(message) { const toast=document.getElementById('toast');toast.textContent=message;toast.style.display='block';window.clearTimeout(toastBodycare.timer);toastBodycare.timer=window.setTimeout(()=>toast.style.display='none',2400); }
async function loadBodycareStore() {
  try {
    const response = await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'bodycare_store_get',token:bodycareSession&&bodycareSession.token||''})});
    const result = await response.json(); if (!result.ok) throw new Error('unavailable');
    bodycareEnabled=!!result.enabled; bodycareOwner=!!result.is_owner; bodycarePricesReady=!!result.prices_configured; markupPercent=Number(result.markup)||0;discountPercent=Number(result.discount)||0;
    try{const otherResponse=await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'store_get',token:bodycareSession&&bodycareSession.token||''})});const otherStore=await otherResponse.json();document.getElementById('scoutStoreLink').style.display=otherStore.ok&&(otherStore.enabled||bodycareOwner)?'inline':'none';}catch(error){}
    if(!bodycareEnabled&&!bodycareOwner){document.body.classList.add('hidden-store');document.getElementById('closed').style.display='block';document.body.style.visibility='visible';return;}
    document.body.classList.remove('hidden-store');document.getElementById('ownerNote').style.display=!bodycareEnabled&&bodycareOwner?'block':'none';
    const catalogResponse=await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'store_products',store:'bodycare',token:bodycareSession&&bodycareSession.token||''})});
    const catalog=await catalogResponse.json();if(!catalog.ok)throw new Error('catalog unavailable');
    bodycareProducts.splice(0,bodycareProducts.length,...catalog.products.map(item=>({id:item.product_key,name:item.name,category:item.category,description:item.description,image:item.image_url,base:Number(result.base_prices[item.product_key]??item.base_price),stock:Number(item.stock),badge:discountPercent>0?'عرض':'عناية'})));
    const filters=document.querySelector('.filters');filters.replaceChildren();
    const categories=[...new Set(bodycareProducts.map(product=>product.category).filter(Boolean))];
    const filterOptions=[['all','كل المنتجات'],...categories.map(value=>[value,value]),...(discountPercent>0?[['offers','العروض']]:[])];
    filterOptions.forEach(([value,label],index)=>{const button=document.createElement('button');button.className='filter'+(index===0?' active':'');button.dataset.cat=value;button.textContent=label;button.addEventListener('click',()=>{activeFilter=value;filters.querySelectorAll('.filter').forEach(item=>item.classList.toggle('active',item===button));renderBodycare();});filters.appendChild(button);});
    renderBodycare();renderBodycareCart();document.body.style.visibility='visible';
  } catch(error) {document.body.classList.add('hidden-store');document.getElementById('closed').style.display='block';document.getElementById('closed').querySelector('h1').textContent='تعذر الاتصال بمتجر فيرجينيا الشرق';document.body.style.visibility='visible';}
}
document.querySelectorAll('.filter').forEach(button=>button.addEventListener('click',()=>{activeFilter=button.dataset.cat;document.querySelectorAll('.filter').forEach(item=>item.classList.toggle('active',item===button));renderBodycare();}));
document.getElementById('search').addEventListener('input',renderBodycare);document.addEventListener('keydown',event=>{if(event.key==='Escape')closeBasket();});
renderBodycare();renderBodycareCart();loadBodycareStore();window.setInterval(loadBodycareStore,30000);
