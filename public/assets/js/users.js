function filterUsersTable(){
    const q = (document.getElementById("userSearch")?.value || "").trim().toLowerCase();
    const role = document.getElementById("userRoleFilter")?.value || "";
    const status = document.getElementById("userStatusFilter")?.value || "";
    let visible = 0;

    document.querySelectorAll(".user-row").forEach(function(row){
        const hay = (row.getAttribute("data-search") || "").toLowerCase();
        const rowRole = row.getAttribute("data-role") || "";
        const rowActive = row.getAttribute("data-active") || "";

        const matchSearch = !q || hay.includes(q);
        const matchRole = !role || rowRole === role;
        const matchStatus = status === "" || rowActive === status;
        const show = matchSearch && matchRole && matchStatus;

        row.style.display = show ? "" : "none";
        if(show){
            visible++;
        }
    });

    const emptyBox = document.getElementById("usersEmptyFilter");
    if(emptyBox){
        emptyBox.classList.toggle("is-visible", visible === 0 && document.querySelectorAll(".user-row").length > 0);
    }
}

function openUserForm(isNew){
    const overlay = document.getElementById("userFormOverlay");
    const title = document.getElementById("userFormTitleText");
    if(overlay){
        overlay.classList.add("is-open");
        document.body.style.overflow = "hidden";
    }
    if(title){
        title.textContent = isNew ? "إضافة مستخدم" : "تعديل مستخدم";
    }
    if(isNew){
        clearUserFormFields();
    }
}

function closeUserForm(){
    const overlay = document.getElementById("userFormOverlay");
    if(overlay){
        overlay.classList.remove("is-open");
        document.body.style.overflow = "";
    }
}

function clearUserFormFields(){
    document.getElementById("id").value = "0";
    document.getElementById("username").value = "";
    document.getElementById("account_type").value = "user";
    document.getElementById("whatsapp_number").value = "";
    document.getElementById("whatsapp_enabled").checked = true;
    document.getElementById("manager_id").value = "0";

    document.getElementById("changePass").checked = true;
    document.getElementById("password").disabled = false;
    document.getElementById("password").value = "";
    syncPasswordChangeFlag();

    const permissionSearch = document.getElementById("permissionSearch");
    if(permissionSearch){
        permissionSearch.value = "";
        filterPermissionCards();
    }

    resetPermissions();
    handleAccountTypeChange("user");
    prepareNewUserPassword();
}

function filterPermissionCards(){
    const input = document.getElementById("permissionSearch");
    const q = input ? input.value.trim().toLowerCase() : "";

    document.querySelectorAll(".perm-card").forEach(function(card){
        const hay = (card.getAttribute("data-search") || "").toLowerCase();
        card.style.display = (!q || hay.includes(q)) ? "flex" : "none";
    });

    document.querySelectorAll(".perm-group-title").forEach(function(title){
        let next = title.nextElementSibling;
        let hasVisible = false;

        while(next && !next.classList.contains("perm-group-title")){
            if(next.classList && next.classList.contains("perm-card") && next.style.display !== "none"){
                hasVisible = true;
                break;
            }
            next = next.nextElementSibling;
        }

        title.style.display = hasVisible ? "block" : "none";
    });
}

function handleAccountTypeChange(accountType){
    togglePerms(accountType);
}

function setScopeRowsForAccountType(accountType){
    document.querySelectorAll('.scope-row').forEach(function(row){
        const ownLabel = row.querySelector('input[value="own"]')?.closest('label');
        const teamLabel = row.querySelector('input[value="team"]')?.closest('label');
        const allLabel = row.querySelector('input[value="all"]')?.closest('label');
        const ownInput = row.querySelector('input[value="own"]');
        const teamInput = row.querySelector('input[value="team"]');

        row.style.display = 'flex';
        if(ownLabel) ownLabel.style.display = 'flex';
        if(teamLabel) teamLabel.style.display = 'flex';
        if(allLabel) allLabel.style.display = 'flex';

        if(accountType === 'user'){
            row.style.display = 'none';
            if(ownInput) ownInput.checked = true;
        }else if(accountType === 'accountant'){
            const card = row.closest('.perm-card');
            const pageName = card?.dataset?.page || '';
            const hay = ((card?.dataset?.search || '') + ' ' + pageName).toLowerCase();
            const canAccountantChooseScope = (
                pageName === 'contract_report' ||
                pageName === 'item_report' ||
                hay.includes('تقرير كل العقود') ||
                hay.includes('تقرير الأصناف') ||
                hay.includes('تقرير الاصناف')
            );

            if(canAccountantChooseScope){
                row.style.display = 'flex';
                if(ownLabel) ownLabel.style.display = 'flex';
                if(teamLabel) teamLabel.style.display = 'flex';
                if(allLabel) allLabel.style.display = 'flex';
            }else{
                row.style.display = 'none';
                if(ownInput) ownInput.checked = true;
            }
        }else if(accountType === 'section_manager'){
            const pageName = row.closest('.perm-card')?.dataset?.page || '';
            const canSectionManagerUseAll = (pageName === 'contract_report' || pageName === 'item_report');

            if(allLabel) allLabel.style.display = canSectionManagerUseAll ? 'flex' : 'none';

            const checkedAll = row.querySelector('input[value="all"]:checked');
            if(checkedAll && !canSectionManagerUseAll && teamInput){
                teamInput.checked = true;
            }
        }else if(accountType === 'finance_manager'){
            // مدير مالي يظهر له خاص / فريقه / الكل
        }else if(accountType === 'commercial_manager'){
            // مدير تجاري يظهر له خاص / فريقه / الكل
        }
    });
}

function togglePerms(accountType){
    const area = document.getElementById("permissionsArea");
    const note = document.getElementById("rolePermissionNote");
    const roleNoteBox = document.getElementById("roleNoteBox");

    if(!area){
        return;
    }

    if(accountType === "admin" || accountType === "commercial_manager"){
        area.style.display = "none";

        if(note){
            note.textContent = (accountType === "commercial_manager") ? "المدير التجاري له صلاحية كاملة مثل الأدمن، ولا يحتاج اختيار صفحات من الكروت." : "الأدمن له صلاحية كاملة، ولا يحتاج اختيار صفحات من الكروت.";
        }

        if(roleNoteBox){
            roleNoteBox.classList.add("admin-mode");
            roleNoteBox.textContent = (accountType === "commercial_manager") ? "نوع المستخدم مدير تجاري: صلاحية كاملة مثل الأدمن بدون كروت." : "نوع المستخدم أدمن: صلاحية كاملة بدون كروت.";
        }

        resetPermissions();
        return;
    }

    area.style.display = "block";
    setScopeRowsForAccountType(accountType);

    if(accountType === 'user' || accountType === 'accountant'){
        if(note){
            note.textContent = (accountType === 'accountant')
                ? "المحاسب: يمكن تحديد نطاق تقرير كل العقود وتقرير الأصناف فقط، وباقي الصفحات تكون خاص تلقائيًا."
                : "المستخدم العادي: اختار الصفحات فقط، والنطاق يكون خاص تلقائيًا.";
        }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = (accountType === 'accountant')
                ? "محاسب: في تقرير العقود وتقرير الأصناف اختر خاص / فريقه / الكل حسب المطلوب، حتى يقدر يراجع ويخصم من الفواتير."
                : "مستخدم: لا يظهر له خاص / فريقه / الكل. أي صفحة يتم تفعيلها تكون على بياناته فقط.";
        }
    }else if(accountType === 'section_manager'){
        if(note){ note.textContent = "مدير القسم: يمكن اختيار خاص أو فريقه، وفي تقارير العقود والأصناف يمكن اختيار الكل أيضًا."; }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = "مدير قسم: خاص = بياناته فقط، فريقه = بياناته + الموظفين التابعين له، الكل متاح في تقرير العقود وتقرير الأصناف فقط.";
        }
    }else if(accountType === 'finance_manager'){
        if(note){ note.textContent = "مدير مالي: يمكن اختيار خاص أو فريقه أو الكل حسب الصفحة."; }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = "مدير مالي: فريقه يشمل المحاسبين والتابعين له حسب المدير المباشر.";
        }
    }else if(accountType === 'commercial_manager'){
        if(note){ note.textContent = "مدير تجاري: يمكن اختيار خاص أو فريقه أو الكل حسب الصفحة."; }
        if(roleNoteBox){
            roleNoteBox.classList.remove("admin-mode");
            roleNoteBox.textContent = "مدير تجاري: فريقه يشمل كل التابعين له على كل المستويات.";
        }
    }
}

function syncPasswordChangeFlag(){
    const check = document.getElementById("changePass");
    const flag = document.getElementById("wantPasswordChange");
    if(flag && check){
        flag.value = check.checked ? "1" : "0";
    }
}

function togglePass(){
    const pass = document.getElementById("password");
    const check = document.getElementById("changePass");

    pass.disabled = !check.checked;
    syncPasswordChangeFlag();

    if(pass.disabled){
        pass.value = "";
    }else{
        pass.focus();
    }
}

function prepareNewUserPassword(){
    const idField = document.getElementById("id");
    const pass = document.getElementById("password");
    const check = document.getElementById("changePass");

    if(!idField || !pass || !check){
        return;
    }

    if(String(idField.value || "0") === "0"){
        check.checked = true;
        pass.disabled = false;
        syncPasswordChangeFlag();
    }
}

document.addEventListener("DOMContentLoaded", prepareNewUserPassword);

function bindUserFormSubmit(){
    const form = document.getElementById("userForm");
    if(!form || form.dataset.submitBound === "1"){
        return;
    }
    form.dataset.submitBound = "1";
    form.addEventListener("submit", function(){
        const pass = document.getElementById("password");
        const check = document.getElementById("changePass");
        if(check && check.checked && pass){
            pass.disabled = false;
        }else if(pass){
            pass.value = "";
        }
        syncPasswordChangeFlag();
    });
}

function resetPermissions(){
    document.querySelectorAll(".perm-check").forEach(function(c){
        c.checked = false;
    });

    document.querySelectorAll(".scope-row input[value='own']").forEach(function(r){
        r.checked = true;
    });
}

function applyDepartment(dept){
    resetPermissions();

    const accountType = document.getElementById("account_type")?.value || "user";
    let scopeValue = "own";
    if(accountType === "section_manager" || accountType === "finance_manager" || accountType === "commercial_manager"){
        scopeValue = "team";
    }

    document.querySelectorAll(`.perm-card[data-depts~="${dept}"]`).forEach(function(card){
        const check = card.querySelector(".perm-check");
        if(check){
            check.checked = true;
        }

        const radio = card.querySelector(`.scope-row input[value="${scopeValue}"]`);
        if(radio){
            radio.checked = true;
        }
    });

    setScopeRowsForAccountType(accountType);
}

function resetForm(){
    clearUserFormFields();
    const title = document.getElementById("userFormTitleText");
    if(title){
        title.textContent = "إضافة مستخدم";
    }
    openUserForm(true);
}

function editUser(u){
    openUserForm(false);

    document.getElementById("id").value = u.id;
    document.getElementById("username").value = u.username;
    document.getElementById("account_type").value = u.account_type || "user";
    document.getElementById("whatsapp_number").value = u.whatsapp_number || "";
    document.getElementById("whatsapp_enabled").checked = String(u.whatsapp_enabled || 0) === "1";
    document.getElementById("manager_id").value = String(u.manager_id || 0);
    document.getElementById("changePass").checked = false;
    document.getElementById("password").value = "";
    document.getElementById("password").disabled = true;
    syncPasswordChangeFlag();

    resetPermissions();
    handleAccountTypeChange(u.account_type || "user");

    fetch("get_user_permissions.php?user_id=" + encodeURIComponent(u.id))
        .then(function(res){
            return res.json();
        })
        .then(function(data){

            if(!Array.isArray(data)){
                return;
            }

            data.forEach(function(p){

                let check = document.querySelector(`input[name="permissions[${p.page_id}][view]"]`);
                if(check){
                    check.checked = true;
                }

                let scope = (["own", "team", "all"].includes(p.scope)) ? p.scope : "own";
                let radio = document.querySelector(`input[name="permissions[${p.page_id}][scope]"][value="${scope}"]`);
                if(radio){
                    radio.checked = true;
                }
            });

            setScopeRowsForAccountType(u.account_type || "user");
        })
        .catch(function(){
            console.warn("تعذر تحميل صلاحيات المستخدم");
        });
}

document.addEventListener("DOMContentLoaded", function(){
    handleAccountTypeChange("user");
    prepareNewUserPassword();
    bindUserFormSubmit();
    filterUsersTable();
});

document.addEventListener("keydown", function(e){
    if(e.key === "Escape"){
        closeUserForm();
    }
});
