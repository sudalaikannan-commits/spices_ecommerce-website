/* SpiceKart storefront JavaScript
   Catalogue + cart are seeded from the static list below, then replaced with
   live data from the PHP/MySQL API (assets/store-db.js) whenever the store is
   installed. Everything falls back to the static data if the API is missing. */
let PRODUCTS = [
  {id:1,name:"Turmeric Powder",category:"Ground Spices",pack:"500g",price:199,old:249,img:"https://thumb.wikimedia.org/wikipedia/commons/thumb/3/3e/Turmeric_Powder_Spelled_Out.jpg/960px-Turmeric_Powder_Spelled_Out.jpg",fb:"assets/prod_turmeric.jpg",rating:4.8,desc:"Bright, aromatic turmeric powder made for everyday cooking.",ingredients:"100% turmeric",usage:"Curries, rice, marinades and drinks."},
  {id:2,name:"Red Chilli Powder",category:"Ground Spices",pack:"500g",price:189,old:239,img:"https://thumb.wikimedia.org/wikipedia/commons/thumb/d/d4/Red_Chili_Powder_%28Lall_Mirch%29_%2849695826571%29.jpg/960px-Red_Chili_Powder_%28Lall_Mirch%29_%2849695826571%29.jpg",fb:"assets/prod_chilli.jpg",rating:4.7,desc:"Bold colour and balanced heat for authentic Indian dishes.",ingredients:"100% red chilli",usage:"Curries, chutneys and spice mixes."},
  {id:3,name:"Coriander Powder",category:"Ground Spices",pack:"500g",price:179,old:219,img:"https://thumb.wikimedia.org/wikipedia/commons/thumb/8/86/Coriander_Seeds.jpg/960px-Coriander_Seeds.jpg",fb:"assets/prod_coriander.jpg",rating:4.8,desc:"Fresh, citrusy coriander powder with a warm aroma.",ingredients:"100% coriander",usage:"Curries, gravies and masalas."},
  {id:4,name:"Garam Masala",category:"Masala Blends",pack:"200g",price:249,old:299,img:"https://thumb.wikimedia.org/wikipedia/commons/thumb/2/23/Garam_Masala_Powder_by_Chlorofeel_Groups.jpg/960px-Garam_Masala_Powder_by_Chlorofeel_Groups.jpg",fb:"assets/prod_garam.jpg",rating:4.9,desc:"A fragrant blend designed to finish Indian dishes beautifully.",ingredients:"Coriander, cumin, pepper, cinnamon and more.",usage:"Add near the end of cooking."},
  {id:5,name:"Black Pepper",category:"Whole Spices",pack:"200g",price:299,old:349,img:"https://thumb.wikimedia.org/wikipedia/commons/thumb/8/81/Tellicherry_Black_Peppercorns.jpg/960px-Tellicherry_Black_Peppercorns.jpg",fb:"assets/prod_pepper.jpg",rating:4.8,desc:"Aromatic whole black pepper with a warm, sharp finish.",ingredients:"100% black pepper",usage:"Grind fresh for best aroma."},
  {id:6,name:"Cardamom",category:"Whole Spices",pack:"100g",price:349,old:399,img:"https://thumb.wikimedia.org/wikipedia/commons/thumb/8/82/Green_Cardamom_Pods.jpg/960px-Green_Cardamom_Pods.jpg",fb:"assets/prod_cardamom.jpg",rating:4.9,desc:"Fragrant green cardamom for sweets, tea and savoury dishes.",ingredients:"100% green cardamom",usage:"Crush pods or use whole."}
];

let CATS = [
  ["cat_whole.jpg","Whole Spices"],
  ["cat_ground.jpg","Ground Spices"],
  ["cat_masala.jpg","Masala Blends"],
  ["cat_herbs.jpg","Herbs & Leaves"],
  ["cat_organic.jpg","Organic Spices"],
  ["cat_combo.jpg","Combo Packs"]
];

let cart = JSON.parse(localStorage.getItem("sk_cart") || "[]");
let wishlist = JSON.parse(localStorage.getItem("sk_wishlist") || "[]");

const params = new URLSearchParams(location.search);
const money = n => "₹" + n.toLocaleString("en-IN");

/* escapes catalog/review values before they are placed into innerHTML templates */
const esc = value => String(value ?? "").replace(/[&<>"']/g, ch => ({
  "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;"
}[ch]));

function save(){
  localStorage.setItem("sk_cart", JSON.stringify(cart));
  localStorage.setItem("sk_wishlist", JSON.stringify(wishlist));
}

function toast(message){
  const t = document.getElementById("toast");
  if(!t) return;
  t.textContent = message;
  t.classList.add("show");
  clearTimeout(window._toastTimer);
  window._toastTimer = setTimeout(() => t.classList.remove("show"), 1800);
}

function cartCount(){
  return cart.reduce((sum,item) => sum + item.qty, 0);
}

function refreshCount(){
  document.querySelectorAll(".cart-count").forEach(el => {
    el.textContent = cartCount();
  });
}

function add(id, qty = 1){
  const item = cart.find(x => x.id === id);
  if(item) item.qty += qty;
  else cart.push({id, qty});

  save();
  refreshCount();
  toast("Added to cart");
}

function remove(id){
  cart = cart.filter(x => x.id !== id);
  save();
  refreshCount();
  renderCart();
  renderDrawer();
  toast("Removed from cart");
}

function changeQty(id, delta){
  const item = cart.find(x => x.id === id);
  if(!item) return;

  item.qty += delta;

  if(item.qty <= 0){
    remove(id);
    return;
  }

  save();
  refreshCount();
  renderCart();
}

function toggleWish(id){
  if(wishlist.includes(id)){
    wishlist = wishlist.filter(x => x !== id);
  }else{
    wishlist.push(id);
  }

  save();
  renderProducts();
  renderWishlist();
  toast(wishlist.includes(id) ? "Added to wishlist" : "Removed from wishlist");
}

function productCard(product){
  const active = wishlist.includes(product.id);
  const link = "product-details.html?id=" + product.id;

  return `
    <article class="card">
      <div class="card-img">
        <a href="${link}" aria-label="${esc(product.name)}">
          <img src="${esc(product.img)}" onerror="this.onerror=null;this.src='${esc(product.fb)}'" alt="${esc(product.name)}">
        </a>
        <span class="badge">Best Seller</span>
        <button class="heart ${active ? "active" : ""}"
                aria-label="Wishlist"
                onclick="toggleWish(${product.id})">
          ${active ? "♥" : "♡"}
        </button>
      </div>

      <div class="card-info">
        <a class="card-title" href="${link}"><h3>${esc(product.name)}</h3></a>
        <div class="pack">${product.pack} · ⭐ ${product.rating}</div>
        <div class="price">
          ${money(product.price)}
          <span class="old">${money(product.old)}</span>
        </div>
        <button class="add" onclick="add(${product.id})">
          🛒 Add to Cart
        </button>
      </div>
    </article>
  `;
}

function renderProducts(list = PRODUCTS, target = "productGrid"){
  const el = document.getElementById(target);
  if(el) el.innerHTML = list.map(productCard).join("");
}

function renderWishlist(){
  const el = document.getElementById("wishlistGrid");
  if(!el) return;

  const list = PRODUCTS.filter(p => wishlist.includes(p.id));

  el.innerHTML = list.length
    ? list.map(productCard).join("")
    : `<div class="empty filters-empty">Your wishlist is empty.<br>
        <a class="primary" style="display:inline-block;margin-top:14px" href="products.html">Explore Spices</a>
      </div>`;
}

/* ---------- product listing: URL filters + sidebar filters + sort ---------- */

const PRICE_BANDS = {
  low:  p => p.price < 200,
  mid:  p => p.price >= 200 && p.price <= 300,
  high: p => p.price > 300
};

function checked(attr){
  return [...document.querySelectorAll(".filters input[" + attr + "]:checked")].map(x => x.dataset[attr.slice(5)]);
}

function matchesFilters(product){
  const cat = params.get("cat");
  const q = (params.get("q") || "").trim().toLowerCase();

  if(cat && product.category !== cat) return false;
  if(params.get("offers") === "1" && !(product.old > product.price)) return false;

  if(q){
    const hay = (product.name + " " + product.category + " " + product.pack + " " + product.desc).toLowerCase();
    if(!hay.includes(q)) return false;
  }

  const cats = checked("data-cat");
  if(cats.length && !cats.includes(product.category)) return false;

  const bands = checked("data-price").filter(b => PRICE_BANDS[b]);
  if(bands.length && !bands.some(b => PRICE_BANDS[b](product))) return false;

  return true;
}

function sortProducts(list){
  const mode = document.getElementById("sort")?.value || "";

  if(mode.includes("Low to High")) return [...list].sort((a, b) => a.price - b.price);
  if(mode.includes("High to Low")) return [...list].sort((a, b) => b.price - a.price);
  return [...list].sort((a, b) => b.rating - a.rating);
}

function renderListing(){
  const grid = document.getElementById("productGrid");
  if(!grid) return;

  const list = sortProducts(PRODUCTS.filter(matchesFilters));

  grid.innerHTML = list.length
    ? list.map(productCard).join("")
    : `<div class="empty filters-empty">No products match your selection.<br>
        <button class="primary" style="display:inline-block;margin-top:14px" onclick="clearFilters()">Clear Filters</button>
      </div>`;

  const count = document.getElementById("resultCount");
  if(count) count.textContent = list.length + (list.length === 1 ? " product" : " products");
}

function clearFilters(){
  document.querySelectorAll(".filters input[type=checkbox]").forEach(box => box.checked = false);
  const sort = document.getElementById("sort");
  if(sort) sort.selectedIndex = 0;
  renderListing();
  toast("Filters cleared");
}

function bindListing(){
  if(!document.getElementById("productGrid")) return;

  document.querySelectorAll(".filters input[type=checkbox]").forEach(box => box.addEventListener("change", renderListing));
  document.getElementById("sort")?.addEventListener("change", renderListing);
  document.querySelector(".mobile-filter")?.addEventListener("click", () => {
    document.querySelector(".filters")?.classList.toggle("show");
  });

  if(params.get("cat")) document.querySelector('.page-title h1').textContent = params.get("cat");

  renderListing();
}

function renderCats(){
  const el = document.getElementById("categoryGrid");
  if(!el) return;

  el.innerHTML = CATS.map(category => `
    <a class="cat" href="products.html?cat=${encodeURIComponent(category[1])}">
      <img src="assets/${category[0]}" alt="${category[1]}">
      <b>${category[1]}</b>
    </a>
  `).join("");
}

function openDrawer(){
  document.getElementById("drawer")?.classList.add("open");
  document.getElementById("backdrop")?.classList.add("open");
  renderDrawer();
}

function closeDrawer(){
  document.getElementById("drawer")?.classList.remove("open");
  document.getElementById("backdrop")?.classList.remove("open");
}

function renderDrawer(){
  const el = document.getElementById("drawerList");
  if(!el) return;

  if(!cart.length){
    el.innerHTML = '<div class="empty">Your cart is empty.</div>';
    const total = document.getElementById("drawerTotal");
    if(total) total.textContent = "₹0";
    return;
  }

  let total = 0;

  el.innerHTML = cart.map(item => {
    const product = PRODUCTS.find(p => p.id === item.id);
    total += product.price * item.qty;

    return `
      <div class="drawer-row">
        <img src="${esc(product.img)}" onerror="this.onerror=null;this.src='${esc(product.fb)}'" alt="${esc(product.name)}">
        <div>
          <h4>${esc(product.name)}</h4>
          <small>${product.pack} × ${item.qty}</small><br>
          <b>${money(product.price * item.qty)}</b>
        </div>
        <button onclick="remove(${product.id})">×</button>
      </div>
    `;
  }).join("");

  document.getElementById("drawerTotal").textContent = money(total);
}

function renderCart(){
  const el = document.getElementById("cartList");
  if(!el) return;

  if(!cart.length){
    el.innerHTML = `
      <div class="empty">
        Your cart is empty.<br>
        <a href="products.html" class="primary" style="margin-top:15px">
          Shop Products
        </a>
      </div>
    `;
    updateSummary();
    return;
  }

  el.innerHTML = cart.map(item => {
    const product = PRODUCTS.find(p => p.id === item.id);

    return `
      <div class="cart-item">
        <img src="${esc(product.img)}" onerror="this.onerror=null;this.src='${esc(product.fb)}'" alt="${esc(product.name)}">

        <div>
          <h3>${esc(product.name)}</h3>
          <p>${product.pack} · ${money(product.price)}</p>

          <div class="cart-actions">
            <div class="mini-qty">
              <button onclick="changeQty(${product.id}, -1)">−</button>
              <span>${item.qty}</span>
              <button onclick="changeQty(${product.id}, 1)">+</button>
            </div>

            <button class="remove" onclick="remove(${product.id})">
              Remove
            </button>
          </div>
        </div>

        <strong>${money(product.price * item.qty)}</strong>
      </div>
    `;
  }).join("");

  updateSummary();
}

function updateSummary(){
  const subtotal = cart.reduce((sum,item) => {
    const product = PRODUCTS.find(p => p.id === item.id);
    return sum + product.price * item.qty;
  }, 0);

  const cfg = window.StoreDB?.config || {};
  const shippingFee = Number(cfg.shipping_fee ?? 49);
  const freeAbove = Number(cfg.free_shipping_above ?? 499);
  const shipping = subtotal === 0 ? 0 : freeAbove > 0 && subtotal >= freeAbove ? 0 : shippingFee;

  const coupon = localStorage.getItem("sk_coupon");
  const stored = Number(localStorage.getItem("sk_coupon_discount"));
  const discount = stored > 0
    ? stored
    : (coupon === "SPICE10" ? Math.round(subtotal * 0.1) : 0);
  const total = Math.max(0, subtotal + shipping - discount);

  document.querySelectorAll(".subtotal").forEach(x => x.textContent = money(subtotal));
  document.querySelectorAll(".shipping").forEach(x => x.textContent = shipping ? money(shipping) : "FREE");
  document.querySelectorAll(".discount").forEach(x => x.textContent = discount ? "-" + money(discount) : "₹0");
  document.querySelectorAll(".grand-total").forEach(x => x.textContent = money(total));
}

function productByQuery(){
  const q = new URLSearchParams(location.search);
  const id = Number(q.get("id") || 0);
  const slug = q.get("slug") || "";

  if(slug){
    const bySlug = PRODUCTS.find(p => p.slug === slug);
    if(bySlug) return bySlug;
  }

  return PRODUCTS.find(p => p.id === id) || PRODUCTS[0];
}

function renderDetail(){
  const product = productByQuery();
  const target = document.getElementById("detailContent");

  if(!target) return;

  document.title = product.name + " — SpiceKart";

  target.innerHTML = `
    <div class="detail-gallery">
      <img id="mainImg" src="${esc(product.img)}" onerror="this.onerror=null;this.src='${esc(product.fb)}'" alt="${esc(product.name)}">
      <div class="thumbs">
        <img class="active"
             src="${esc(product.img)}"
             onerror="this.onerror=null;this.src='${esc(product.fb)}'"
             alt="${esc(product.name)}"
             onclick="document.getElementById('mainImg').src=this.src">
      </div>
    </div>

    <div class="detail-info">
      <div class="breadcrumbs">
        Home / Products / ${esc(product.name)}
      </div>

      <h1>${esc(product.name)}</h1>

      <div class="rating">
        ★★★★★ ${product.rating} · 120 Reviews
      </div>

      <div class="detail-price">
        ${money(product.price)}
        <span class="old">${money(product.old)}</span>
      </div>

      <p class="desc">${esc(product.desc)}</p>

      <b>Pack Size</b>
      <div class="sizes">
        <button class="size selected">100g</button>
        <button class="size">${product.pack}</button>
        <button class="size">1kg</button>
      </div>

      <b>Quantity</b>
      <div class="qty">
        <button onclick="detailQty(-1)">−</button>
        <span id="detailQty">1</span>
        <button onclick="detailQty(1)">+</button>
      </div>

      <div class="detail-actions">
        <button class="primary"
                onclick="add(${product.id}, Number(document.getElementById('detailQty').textContent))">
          Add to Cart
        </button>

        <button class="primary"
                style="background:var(--g)"
                onclick="add(${product.id});location.href='checkout.html'">
          Buy Now
        </button>
      </div>

      <div class="benefits">
        <div>🚚 Free Shipping<br>Above ₹499</div>
        <div>🔒 Secure Payment<br>100% Safe</div>
        <div>↩ Easy Returns<br>Hassle Free</div>
      </div>

      <div class="tabs">
        <div class="tab-buttons">
          <button class="active" onclick="tab(this,'Description')">Description</button>
          <button onclick="tab(this,'Ingredients')">Ingredients</button>
          <button onclick="tab(this,'Usage')">Usage</button>
          <button onclick="tab(this,'Reviews')">Reviews</button>
        </div>

        <div class="tab-content" id="tabContent">
          ${esc(product.desc)}
        </div>
      </div>
    </div>
  `;
}

function detailQty(delta){
  const el = document.getElementById("detailQty");
  if(el) el.textContent = Math.max(1, Number(el.textContent) + delta);
}

function tab(button, name){
  document.querySelectorAll(".tab-buttons button")
    .forEach(x => x.classList.remove("active"));

  button.classList.add("active");

  const product = productByQuery();

  const text = {
    Description: product.desc,
    Ingredients: product.ingredients,
    Usage: product.usage,
    Reviews: "Customers love this product."
  };

  document.getElementById("tabContent").textContent = text[name];
}

function applyCoupon(){
  const input = document.getElementById("couponInput");
  const code = input?.value.trim().toUpperCase();

  if(!code) return;

  const subtotal = cart.reduce((sum, item) => {
    const product = PRODUCTS.find(p => p.id === item.id);
    return sum + (product ? product.price * item.qty : 0);
  }, 0);

  const finish = discount => {
    localStorage.setItem("sk_coupon", code);
    localStorage.setItem("sk_coupon_discount", String(discount));
    toast(code + " applied");
    updateSummary();
  };

  if(window.StoreDB?.enabled){
    window.StoreDB.coupon(code, subtotal).then(result => {
      if(result?.ok){
        finish(result.discount);
      }else if(result?.fallback){
        if(code === "SPICE10"){ finish(Math.round(subtotal * 0.1)); }
        else toast("Try coupon SPICE10");
      }else{
        toast(result?.error || "Invalid coupon code");
      }
    });
    return;
  }

  if(code === "SPICE10"){
    finish(Math.round(subtotal * 0.1));
  }else{
    toast("Try coupon SPICE10");
  }
}

function placeDemoOrder(){
  if(!cart.length){
    toast("Cart is empty");
    return;
  }

  const saveDemoOrder = () => {
    localStorage.setItem(
      "sk_last_order",
      JSON.stringify({
        id:"#SK" + Date.now().toString().slice(-6),
        total:document.querySelector(".grand-total")?.textContent || "₹0",
        date:new Date().toLocaleDateString(),
        status:"Confirmed"
      })
    );
    location.href = "order-success.html";
  };

  if(!window.StoreDB?.enabled){
    saveDemoOrder();
    return;
  }

  const checkout = JSON.parse(localStorage.getItem("sk_checkout") || "{}");
  const cfg = window.StoreDB?.config || {};
  const button = document.querySelector('button[onclick*="placeDemoOrder"]');

  /* hide methods the admin has switched off */
  document.querySelectorAll("[data-method='cod']").forEach(el => {
    el.closest(".payment-method").classList.toggle("hidden", cfg.cod_enabled === false);
  });
  document.querySelectorAll("[data-method='online']").forEach(el => {
    const row = el.closest(".payment-method");
    const offline = cfg.online_payment_enabled === false;
    row.classList.toggle("hidden", offline);
    if(offline && el.checked){ el.checked = false; }
  });
  if(!document.querySelector('input[name="pay"]:checked')){
    const firstVisible = [...document.querySelectorAll('input[name="pay"]')]
      .find(el => !el.closest(".payment-method")?.classList.contains("hidden"));
    if(firstVisible) firstVisible.checked = true;
  }
  if(!document.querySelector('input[name="pay"]:checked')){
    toast("No payment method is available right now");
    return;
  }

  const method = (document.querySelector('input[name="pay"]:checked')?.value || "UPI").toUpperCase();

  if(button){ button.disabled = true; button.textContent = "Placing order…"; }

  window.StoreDB.placeOrder({
    customer:{
      name:checkout.name || "",
      email:checkout.email || "",
      phone:checkout.phone || "",
      address:checkout.address || "",
      city:checkout.city || "",
      state:checkout.state || "",
      pincode:checkout.pincode || ""
    },
    items:cart.map(item => ({ id:item.id, qty:item.qty })),
    coupon:localStorage.getItem("sk_coupon") || "",
    payment_method:["COD","CARD","UPI","NETBANKING"].includes(method) ? method : "COD"
  }).then(result => {
    if(result?.ok){
      localStorage.setItem(
        "sk_last_order",
        JSON.stringify({
          id:"#" + result.order_code,
          order_code:result.order_code,
          total:result.total_text || "₹" + result.total,
          date:new Date().toLocaleDateString(),
          status:"Confirmed",
          email:checkout.email || ""
        })
      );
      location.href = "order-success.html";
    }else{
      toast(result?.error || "Could not place the order");
      if(button){ button.disabled = false; button.textContent = "Place Order"; }
    }
  });
}

function doSearch(){
  const input = document.getElementById("searchInput");
  const query = input?.value.trim();

  if(query){
    location.href = "products.html?q=" + encodeURIComponent(query);
  }
}

/* ---------- home page hero slideshow ----------
   4 supplied images (2123x741 wide banners):
     hero_slide1.png  Rustic Spice Market Still Life
     hero_slide2.png  Rustic Spice Market Still Life (1)
     hero_slide3.png  Rustic Spice Market Still Life (2)
     hero_slide4.png  Rustic Farm Market Produce Display
   Each alt slide is anchored right so the subject sits on the right half and
   the left half stays open for the hero text. */

const HERO_SLIDES = [
  {src:"assets/hero_slide1.png", alt:"Rustic spice market still life", alt_:true},
  {src:"assets/hero_slide2.png", alt:"Rustic spice market still life", alt_:true},
  {src:"assets/hero_slide3.png", alt:"Rustic spice market still life", alt_:true},
  {src:"assets/hero_slide4.png", alt:"Rustic farm market produce display", alt_:true}
];

function initHeroSlides(){
  const hero = document.querySelector(".hero");
  if(!hero) return;

  const first = hero.querySelector(":scope > img");
  const dots = hero.querySelector(".dots");

  if(!first || !dots) return;

  first.classList.add("hero-slide", "active");

  HERO_SLIDES.forEach((slide, i) => {
    if(i === 0) return;

    const img = document.createElement("img");
    img.className = slide.alt_ ? "hero-slide hero-slide--alt" : "hero-slide";
    img.src = slide.src;
    img.alt = slide.alt;
    hero.insertBefore(img, dots);
  });

  const dotsNew = HERO_SLIDES.map((_, i) => `<i data-slide="${i}" class="${i === 0 ? "active" : ""}"></i>`).join("");
  dots.innerHTML = dotsNew;

  const dotsAll = [...dots.querySelectorAll("i")];
  const slides = [...hero.querySelectorAll(".hero-slide")];
  let index = 0;
  let timer;

  function go(next){
    index = (next + slides.length) % slides.length;
    slides.forEach((s, i) => s.classList.toggle("active", i === index));
    dotsAll.forEach((d, i) => d.classList.toggle("active", i === index));
  }

  function play(){
    clearInterval(timer);
    timer = setInterval(() => go(index + 1), 3500);
  }

  dots.addEventListener("click", e => {
    const dot = e.target.closest("i[data-slide]");
    if(!dot) return;
    go(Number(dot.dataset.slide));
    play();
  });

  hero.addEventListener("mouseenter", () => clearInterval(timer));
  hero.addEventListener("mouseleave", play);

  play();
}

/* ---------- mobile slide menu ---------- */

const MENU_LINKS = [
  ["Home", "index.html"],
  ["All Products", "products.html"],
  ["Whole Spices", "products.html?cat=Whole%20Spices"],
  ["Ground Spices", "products.html?cat=Ground%20Spices"],
  ["Masala Blends", "products.html?cat=Masala%20Blends"],
  ["Herbs & Leaves", "products.html?cat=Herbs%20%26%20Leaves"],
  ["Organic Spices", "products.html?cat=Organic%20Spices"],
  ["Combo Packs", "products.html?cat=Combo%20Packs"],
  ["Offers", "products.html?offers=1"]
];

const MENU_ACCOUNT = [
  ["My Account", "account.html"],
  ["My Orders", "orders.html"],
  ["Wishlist", "wishlist.html"],
  ["Cart", "cart.html"],
  ["Login / Register", "login.html"],
  ["Contact Us", "contact.html"],
  ["About Us", "about.html"]
];

function buildMenu(){
  if(document.querySelector(".mnav")) return;

  const panel = document.createElement("aside");
  panel.className = "mnav";
  panel.id = "mnav";
  panel.innerHTML = `
    <div class="mnav-head">
      <h3>SpiceKart</h3>
      <button class="mnav-close" aria-label="Close menu" onclick="closeMenu()">×</button>
    </div>
    <div class="mnav-label">SHOP</div>
    ${MENU_LINKS.map(([t, h]) => `<a href="${h}">${t}</a>`).join("")}
    <div class="mnav-label">ACCOUNT</div>
    ${MENU_ACCOUNT.map(([t, h]) => `<a href="${h}">${t}</a>`).join("")}
    <p class="mnav-foot">Free Shipping above ₹499 · 100% Pure &amp; Natural</p>`;

  const shade = document.createElement("div");
  shade.className = "mnav-backdrop";
  shade.id = "mnavBackdrop";
  shade.addEventListener("click", closeMenu);

  document.body.append(shade, panel);
}

function openMenu(){
  buildMenu();
  document.getElementById("mnav")?.classList.add("open");
  document.getElementById("mnavBackdrop")?.classList.add("open");
}

function closeMenu(){
  document.getElementById("mnav")?.classList.remove("open");
  document.getElementById("mnavBackdrop")?.classList.remove("open");
}

/* ---------- small UI behaviours ---------- */

function bindUI(){
  /* desktop header links (hamburger is hidden on desktop by CSS) */
  const brand = document.querySelector(".header .brand");

  if(brand && !document.querySelector(".head-links")){
    const links = document.createElement("nav");
    links.className = "head-links";
    links.innerHTML = [
      ["Home", "index.html"],
      ["About Us", "about.html"],
      ["Contact Us", "contact.html"]
    ].map(([t, h]) => `<a href="${h}">${t}</a>`).join("");
    brand.insertAdjacentElement("afterend", links);
  }

  /* mobile menu trigger (header ☰) */
  document.querySelector(".header > button.head-action")?.addEventListener("click", openMenu);

  /* esc closes drawer + menu */
  document.addEventListener("keydown", e => {
    if(e.key !== "Escape") return;
    closeMenu();
    closeDrawer();
  });

  /* hero slider dots (visual state, images come from the backend later) */
  document.querySelectorAll(".dots i").forEach(dot => {
    dot.style.cursor = "pointer";
    dot.addEventListener("click", () => {
      document.querySelectorAll(".dots i").forEach(d => d.classList.remove("active"));
      dot.classList.add("active");
    });
  });

  /* pack-size selector on product details */
  document.querySelector(".sizes")?.addEventListener("click", e => {
    const btn = e.target.closest(".size");
    if(!btn) return;
    document.querySelectorAll(".size").forEach(x => x.classList.remove("selected"));
    btn.classList.add("selected");
  });

  /* payment method selection (frontend only — no real payment) */
  document.querySelector(".checkout")?.addEventListener("change", e => {
    if(e.target.name !== "pay") return;
    document.querySelectorAll(".payment-method").forEach(p => p.classList.remove("selected"));
    e.target.closest(".payment-method")?.classList.add("selected");
  });

  /* placeholders / empty anchors get a real response instead of jumping to top */
  document.addEventListener("click", e => {
    const link = e.target.closest('a[href="#"]');
    if(!link) return;
    e.preventDefault();
    toast("This link will be connected with the backend later");
  });

  /* footer year + active mobile tab */
  document.querySelectorAll(".footer-year").forEach(el => el.textContent = new Date().getFullYear());

  const page = location.pathname.split("/").pop() || "index.html";
  document.querySelectorAll(".mobile-bottom a").forEach(a => {
    if(a.getAttribute("href") === page) a.classList.add("active");
  });
}

/* ---------- form UI buttons (demo only, no backend) ---------- */

function saveProfile(){
  const data = {};

  document.querySelectorAll(".account .field input").forEach(input => {
    data[input.closest(".field").childNodes[0].textContent.trim()] = input.value;
  });

  localStorage.setItem("sk_profile", JSON.stringify(data));
  toast("Profile saved (demo — stored in browser only)");
}

function prefillProfile(){
  const data = JSON.parse(localStorage.getItem("sk_profile") || "{}");

  document.querySelectorAll(".account .field input").forEach(input => {
    const key = input.closest(".field").childNodes[0].textContent.trim();
    if(data[key]) input.value = data[key];
  });
}

function goCheckout(){
  const missing = [...document.querySelectorAll(".checkout input[required]")].filter(i => !i.value.trim());

  if(missing.length){
    missing[0].focus();
    toast("Please fill the delivery details first");
    return false;
  }

  const field = label => {
    const el = [...document.querySelectorAll(".checkout .field")]
      .find(f => f.childNodes[0]?.textContent.trim().toUpperCase().startsWith(label.toUpperCase()))
      ?.querySelector("input, textarea, select");
    return el ? el.value.trim() : "";
  };

  localStorage.setItem("sk_checkout", JSON.stringify({
    name:field("Full Name"),
    phone:field("Phone"),
    email:field("Email"),
    pincode:field("Pincode"),
    address:field("Address"),
    city:field("City"),
    state:field("State")
  }));

  toast("Delivery details saved");
  return true;
}

/* ---------- live data (PHP/MySQL) ---------- */

function loadStoreDb(){
  if(window.StoreDB) return window.StoreDB.load();

  return new Promise(resolve => {
    const script = document.createElement("script");
    script.src = "assets/store-db.js";
    script.onload = () => resolve(window.StoreDB ? window.StoreDB.load() : null);
    script.onerror = () => resolve(null);
    document.head.appendChild(script);
  }).catch(() => null);
}

function renderLiveOrders(){
  const page = document.querySelector(".page.container");
  if(!page || !window.StoreDB?.enabled) return;

  const checkout = JSON.parse(localStorage.getItem("sk_checkout") || "{}");
  const lastOrder = JSON.parse(localStorage.getItem("sk_last_order") || "{}");
  const email = checkout.email || lastOrder.email;
  if(!email) return;

  const existing = page.querySelectorAll(".order");
  existing.forEach(node => node.remove());

  const heading = document.createElement("h2");
  heading.textContent = "Live order history";
  heading.style.cssText = "font-size:16px;margin:18px 0 10px";
  page.appendChild(heading);

  /* every server-side value is written with textContent — never interpolated into innerHTML */
  const text = (tag, value, cssText) => {
    const node = document.createElement(tag);
    node.textContent = value;
    if(cssText) node.style.cssText = cssText;
    return node;
  };

  window.StoreDB.orders(email).then(result => {
    if(!result) return;

    const wrap = document.createElement("div");
    wrap.className = "order";

    if(!result.orders.length){
      const head = document.createElement("div");
      head.className = "order-head";
      const left = document.createElement("div");
      left.appendChild(text("b", "No orders yet"));
      left.appendChild(text("p", "Orders placed with " + email + " appear here.", "font-size:11px;color:#777"));
      head.appendChild(left);
      wrap.appendChild(head);
      page.appendChild(wrap);
      return;
    }

    result.orders.forEach(order => {
      const card = document.createElement("div");
      card.className = "order";

      const head = document.createElement("div");
      head.className = "order-head";
      const left = document.createElement("div");
      left.appendChild(text("b", "#" + order.order_code));
      left.appendChild(text("p", new Date(order.placed_at).toLocaleDateString() +
        " · " + order.items.length + " item(s)", "font-size:11px;color:#777"));
      head.appendChild(left);

      const status = String(order.status || "");
      const known = ["pending","confirmed","packed","shipped","delivered","cancelled","refunded"];
      const badge = text("span", status.charAt(0).toUpperCase() + status.slice(1));
      badge.className = "status" + (known.includes(status.toLowerCase()) ? "" : " pending");
      if(/pending|processing/i.test(status)) badge.className += " pending";
      head.appendChild(badge);
      card.appendChild(head);

      const names = order.items
        .map(i => i.product_name + (i.quantity > 1 ? " x" + i.quantity : ""))
        .join(", ");
      const foot = document.createElement("div");
      foot.style.cssText = "margin-top:12px;display:flex;justify-content:space-between;font-size:13px";
      foot.appendChild(text("span", names));
      foot.appendChild(text("b", "₹" + Number(order.total).toLocaleString("en-IN")));
      card.appendChild(foot);

      wrap.appendChild(card);
    });

    page.appendChild(wrap);
  });
}

document.addEventListener("DOMContentLoaded", async () => {
  await loadStoreDb();

  if(window.StoreDB?.enabled){
    if(window.StoreDB.products?.length) PRODUCTS = window.StoreDB.products;
    if(window.StoreDB.cats?.length) CATS = window.StoreDB.cats;
  }

  refreshCount();
  if(!document.querySelector(".filters")) renderProducts();
  renderCats();
  renderDetail();
  renderCart();
  renderWishlist();
  renderLiveOrders();
  bindListing();
  bindUI();
  initHeroSlides();
  prefillProfile();

  document.getElementById("searchBtn")?.addEventListener("click", doSearch);

  document.getElementById("searchInput")
    ?.addEventListener("keydown", event => {
      if(event.key === "Enter") doSearch();
    });

  document.getElementById("cartBtn")?.addEventListener("click", openDrawer);
  document.getElementById("backdrop")?.addEventListener("click", closeDrawer);
});
