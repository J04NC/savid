function initSearch(){

if(typeof systemRoutes==="undefined") return;

const overlay=document.getElementById("searchOverlay");
const input=document.getElementById("searchInput");
const results=document.getElementById("searchResults");

document.addEventListener("keydown",function(e){

if(e.ctrlKey && e.key==="k"){

e.preventDefault();

overlay.classList.add("active");

input.focus();

}

if(e.key==="Escape"){

overlay.classList.remove("active");

input.value="";
results.innerHTML="";

}

});

input.addEventListener("input",function(){

const value=this.value.toLowerCase();

results.innerHTML="";

systemRoutes
.filter(r=>r.name.toLowerCase().includes(value))
.forEach(r=>{

const div=document.createElement("div");

div.className="search-item";

div.innerText=r.name;

div.onclick=()=>window.location=r.url;

results.appendChild(div);

});

});

}
