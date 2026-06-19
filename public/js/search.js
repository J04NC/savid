function initSearch(){

if(typeof systemRoutes==="undefined") return;

const overlay=document.getElementById("searchOverlay");
const input=document.getElementById("searchInput");
const results=document.getElementById("searchResults");

if(!overlay || !input || !results) return;

function closeSearch(){
overlay.classList.remove("active");
overlay.setAttribute("aria-hidden","true");
input.value="";
results.innerHTML="";
}

function openSearch(){
overlay.classList.add("active");
overlay.setAttribute("aria-hidden","false");
input.focus();
}

document.addEventListener("keydown",function(e){

if(e.ctrlKey && e.key==="k"){

e.preventDefault();
openSearch();

}

if(e.key==="Escape" && overlay.classList.contains("active")){

e.preventDefault();
closeSearch();

}

});

overlay.addEventListener("click",function(e){
if(e.target===overlay){
closeSearch();
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
