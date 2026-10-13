let storeOwner = false;
let storePricesConfirmed = false;
const SHOP_SESSION = JSON.parse(localStorage.getItem('egga_session') || 'null');

const baseCatalogRender = render;
render = function () {
  baseCatalogRender();
  if (storePricesConfirmed) document.querySelectorAll('.price small').forEach(label => label.remove());
};

function showCheckout() {
  if (!Object.keys(cart).length) return;
  if (!storePricesConfirmed) return notice('الأسعار لم يعتمدها المالك بعد');
  document.getElementById('orderForm').hidden = false;
  document.getElementById('buyerName').focus({ preventScroll: true });
}

async function trackStoreOrder(event){
  event.preventDefault();
  const node=document.getElementById('trackingResult');node.textContent='جارٍ البحث...';
  try{
    const response=await fetch('api/index.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'store_order_track',order_code:document.getElementById('trackCode').value,phone:document.getElementById('trackPhone').value})});
    const result=await response.json();if(!result.ok){node.textContent=result.msg;return;}
    const labels={new:'تم استلام الطلب',confirmed:'تم تأكيد الطلب',processing:'قيد التجهيز',shipped:'تم الشحن',fulfilled:'تم التسليم',cancelled:'ملغي'};
    node.innerHTML=`<b>${esc(result.order.order_code)} · ${labels[result.order.status]||esc(result.order.status)}</b><br>${result.order.events.map(item=>`${labels[item.new_status]||esc(item.new_status)} · ${esc(item.created_at)}`).join('<br>')}`;
  }catch(error){node.textContent='تعذر الاتصال، حاول مرة أخرى';}
}

async function submitOrder(event) {
  event.preventDefault();
  const button = document.getElementById('submitOrderButton');
  button.disabled = true;
  button.textContent = 'جارٍ تسجيل الطلب...';
  try {
    const response = await fetch('api/index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'store_order',
        token: SHOP_SESSION && SHOP_SESSION.token || '',
        name: document.getElementById('buyerName').value,
        phone: document.getElementById('buyerPhone').value,
        gov: document.getElementById('buyerGov').value,
        address: document.getElementById('buyerAddress').value,
        note: document.getElementById('buyerNote').value,
        website: document.getElementById('buyerWebsite').value,
        items: Object.entries(cart).map(([id, quantity]) => ({ id, quantity }))
      })
    });
    const result = await response.json();
    if (!result.ok) return notice(result.msg);
    cart = {};
    persist();
    document.getElementById('cartSummary').hidden = true;
    document.getElementById('orderForm').hidden = true;
    const success = document.getElementById('orderSuccess');
    success.hidden = false;
    success.textContent = `تم تسجيل الطلب بنجاح. رقم الطلب: ${result.order_code}. الإجمالي: ${money(result.total)}. سيتواصل فريق المتجر لتأكيد الشحن والدفع.`;
    document.getElementById('orderForm').reset();
  } catch (error) {
    notice('تعذر الاتصال بالمتجر، حاول مرة أخرى');
  } finally {
    button.disabled = false;
    button.textContent = 'تأكيد الطلب';
  }
}

async function loadStoreSettings() {
  try {
    const response = await fetch('api/index.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'store_get', token: SHOP_SESSION && SHOP_SESSION.token || '' })
    });
    const result = await response.json();
    if (!result.ok) throw new Error('store unavailable');
    storeOwner = !!result.is_owner;
    storePricesConfirmed = !!result.prices_configured;
    const catalogResponse = await fetch('api/index.php', { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'store_products',store:'scout',token:SHOP_SESSION&&SHOP_SESSION.token||''}) });
    const catalog = await catalogResponse.json();
    if (!catalog.ok) throw new Error('catalog unavailable');
    const catalogProducts = catalog.products.map(item=>({id:item.product_key,name:item.name,cat:item.category,type:item.category,description:item.description,image:item.image_url,badge:item.category,price:Number(item.price),stock:Number(item.stock)}));
    products.splice(0,products.length,...catalogProducts);
    const categories=[...new Set(products.map(product=>product.cat).filter(Boolean))];
    const nav=document.querySelector('.categories');nav.replaceChildren();
    [['all','كل المنتجات'],...categories.map(value=>[value,value])].forEach(([value,label])=>{const button=document.createElement('button');button.className='cat-btn'+(value==='all'?' active':'');button.dataset.cat=value;button.textContent=label;button.addEventListener('click',()=>{category=value;nav.querySelectorAll('.cat-btn').forEach(item=>item.classList.toggle('active',item===button));render();});nav.appendChild(button);});
    try {
      const otherResponse = await fetch('api/index.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'bodycare_store_get', token: SHOP_SESSION && SHOP_SESSION.token || '' }) });
      const otherStore = await otherResponse.json();
      document.getElementById('bodycareStoreLink').style.display = otherStore.ok && (otherStore.enabled || storeOwner) ? 'inline' : 'none';
    } catch (error) {}

    if (!result.enabled && !storeOwner) {
      document.body.classList.add('store-closed');
      document.getElementById('storeUnavailable').style.display = 'block';
      document.body.style.visibility = 'visible';
      return;
    }

    document.body.classList.remove('store-closed');
    document.getElementById('storeOwnerNote').style.display = !result.enabled && storeOwner ? 'block' : 'none';
    for (const product of products) {
      if (Number.isFinite(Number(result.prices[product.id]))) product.price = Number(result.prices[product.id]);
    }
    document.getElementById('priceNote').textContent = storePricesConfirmed
      ? 'الأسعار معتمدة من إدارة المتجر. لا يشمل الإجمالي تكلفة الشحن.'
      : 'الأسعار مبدئية حتى اعتمادها من المالك. لا يشمل الإجمالي تكلفة الشحن.';
    render();
    updateCart();
    document.body.style.visibility = 'visible';
  } catch (error) {
    document.body.classList.add('store-closed');
    document.getElementById('storeUnavailable').style.display = 'block';
    document.getElementById('storeUnavailable').querySelector('h1').textContent = 'تعذر الاتصال بمتجر نخبة الشرق';
    document.body.style.visibility = 'visible';
  }
}

loadStoreSettings();
window.setInterval(loadStoreSettings, 30000);
