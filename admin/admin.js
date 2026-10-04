
const AP=[
["#1001","Arjun","₹409","Pending"],["#1002","Priya","₹520","Confirmed"],["#1003","Karthik","₹230","Processing"],["#1004","Divya","₹780","Shipped"],["#1005","Surya","₹230","Delivered"]
];
function statusClass(s){return s==="Pending"?"pending":""}
function adminOrders(){const e=document.getElementById("adminOrders");if(e)e.innerHTML=AP.map(o=>`<tr><td>${o[0]}</td><td>${o[1]}</td><td>${o[2]}</td><td><span class="status ${statusClass(o[3])}">${o[3]}</span></td><td><button class="admin-btn" onclick="toast('Order ${o[0]} opened')">View</button></td></tr>`).join("")}
function toast(m){const t=document.getElementById("adminToast");if(!t)return;t.textContent=m;t.style.display="block";setTimeout(()=>t.style.display="none",1600)}
document.addEventListener("DOMContentLoaded",adminOrders);
