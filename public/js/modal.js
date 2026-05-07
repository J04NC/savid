function openModal(action,id){

fetch("?url=action/"+action+"/"+id)
.then(r=>r.text())
.then(html=>{

let modal = document.createElement("div")
modal.classList.add("modal")

modal.innerHTML = html

document.body.appendChild(modal)

})

}
