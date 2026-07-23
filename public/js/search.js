function initSearch(){

if(typeof systemRoutes==="undefined") return;

const overlay=document.getElementById("searchOverlay");
const input=document.getElementById("searchInput");
const results=document.getElementById("searchResults");
const openBtn=document.getElementById("btnOpenSearch");

if(!overlay || !input || !results) return;

const MAX_RESULTS=8;
let currentMatches=[];
let selectedIndex=-1;

function escapeHtml(str){
    return String(str)
        .replace(/&/g,"&amp;")
        .replace(/</g,"&lt;")
        .replace(/>/g,"&gt;")
        .replace(/"/g,"&quot;");
}

function highlightMatch(name, query){
    if(!query) return escapeHtml(name);
    const idx=name.toLowerCase().indexOf(query.toLowerCase());
    if(idx===-1) return escapeHtml(name);
    const before=name.slice(0, idx);
    const match=name.slice(idx, idx+query.length);
    const after=name.slice(idx+query.length);
    return escapeHtml(before) + "<mark>" + escapeHtml(match) + "</mark>" + escapeHtml(after);
}

function rankMatches(query){
    const q=query.trim().toLowerCase();
    if(!q) return [];

    const startsWith=[];
    const includes=[];

    systemRoutes.forEach(function(r){
        const name=r.name.toLowerCase();
        const idx=name.indexOf(q);
        if(idx===0){
            startsWith.push(r);
        } else if(idx>0){
            includes.push(r);
        }
    });

    startsWith.sort(function(a,b){ return a.name.localeCompare(b.name); });
    includes.sort(function(a,b){ return a.name.localeCompare(b.name); });

    return startsWith.concat(includes).slice(0, MAX_RESULTS);
}

function setSelectedIndex(i){
    selectedIndex=i;
    Array.from(results.children).forEach(function(el, idx){
        el.classList.toggle("active", idx===selectedIndex);
    });
}

function moveSelection(delta){
    if(currentMatches.length===0) return;
    let next=selectedIndex+delta;
    if(next<0) next=currentMatches.length-1;
    if(next>=currentMatches.length) next=0;
    setSelectedIndex(next);
    const activeEl=results.children[next];
    if(activeEl && activeEl.scrollIntoView) activeEl.scrollIntoView({block:"nearest"});
}

function goToSelected(){
    if(selectedIndex>=0 && currentMatches[selectedIndex]){
        window.location=currentMatches[selectedIndex].url;
    }
}

function renderResults(query){
    results.innerHTML="";
    currentMatches=rankMatches(query);
    selectedIndex=currentMatches.length>0 ? 0 : -1;

    if(query.trim()!=="" && currentMatches.length===0){
        const empty=document.createElement("div");
        empty.className="search-empty";
        empty.innerText="Sin resultados para \"" + query.trim() + "\"";
        results.appendChild(empty);
        return;
    }

    currentMatches.forEach(function(r, i){
        const div=document.createElement("div");
        div.className="search-item" + (i===selectedIndex ? " active" : "");
        div.innerHTML=highlightMatch(r.name, query.trim());
        div.addEventListener("mouseenter", function(){
            setSelectedIndex(i);
        });
        div.addEventListener("click", function(){
            window.location=r.url;
        });
        results.appendChild(div);
    });
}

function closeSearch(){
    overlay.classList.remove("active");
    overlay.setAttribute("aria-hidden","true");
    input.value="";
    results.innerHTML="";
    currentMatches=[];
    selectedIndex=-1;
}

function openSearch(){
    overlay.classList.add("active");
    overlay.setAttribute("aria-hidden","false");
    input.focus();
    renderResults("");
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

if(openBtn){
    openBtn.addEventListener("click", openSearch);
}

overlay.addEventListener("click",function(e){
if(e.target===overlay){
closeSearch();
}
});

input.addEventListener("input",function(){
    renderResults(this.value);
});

input.addEventListener("keydown", function(e){
    if(e.key==="ArrowDown"){
        e.preventDefault();
        moveSelection(1);
    } else if(e.key==="ArrowUp"){
        e.preventDefault();
        moveSelection(-1);
    } else if(e.key==="Enter"){
        e.preventDefault();
        goToSelected();
    }
});

}
