function initTheme(){

const button=document.getElementById("toggleTheme");

if(!button) return;

const savedTheme=localStorage.getItem("theme");

if(savedTheme){

document.body.classList.remove("dark-mode","light-mode");
document.body.classList.add(savedTheme);

button.textContent=savedTheme==="light-mode"?"🌙":"☀️";

}

button.addEventListener("click",function(){

document.body.classList.toggle("light-mode");
document.body.classList.toggle("dark-mode");

let currentTheme=document.body.classList.contains("light-mode")
? "light-mode"
: "dark-mode";

localStorage.setItem("theme",currentTheme);

button.textContent=currentTheme==="light-mode"?"🌙":"☀️";

});

}
