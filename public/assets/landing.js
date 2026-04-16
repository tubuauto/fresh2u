(() => {
  const ROLE_PAGE_MAP = {
    customer: "/pages/customer.html",
    leader: "/pages/leader.html",
    merchant: "/pages/merchant.html",
    supply_partner: "/pages/supply_partner.html",
    pickup_hub: "/pages/pickup_hub.html",
    driver: "/pages/driver.html",
    admin: "/pages/admin.html",
  };

  function byId(id) {
    return document.getElementById(id);
  }

  function roleTarget(role) {
    return ROLE_PAGE_MAP[role] || "/pages/index.html";
  }

  function setStatus(id, message, type = "") {
    const target = byId(id);
    if (!target) return;
    target.textContent = message || "";
    target.className = "status-text" + (type ? ` ${type}` : "");
  }

  async function api(path, method = "GET", body = null, token = "", tenant = "") {
    const headers = { "Content-Type": "application/json" };

    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }

    if (tenant) {
      headers["X-Tenant-Merchant-Id"] = tenant;
    }

    const response = await fetch(path, {
      method,
      headers,
      body: body ? JSON.stringify(body) : null,
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }

    return data;
  }

  function saveAuth(token, tenant, user) {
    localStorage.setItem("fresh2u_token", token || "");
    localStorage.setItem("fresh2u_tenant", tenant || "");
    localStorage.setItem("fresh2u_user", JSON.stringify(user || null));
  }

  function clearAuth() {
    localStorage.removeItem("fresh2u_token");
    localStorage.removeItem("fresh2u_tenant");
    localStorage.removeItem("fresh2u_user");
  }

  async function showSessionHintIfLoggedIn() {
    const token = localStorage.getItem("fresh2u_token") || "";
    if (!token) return;

    const tenant = localStorage.getItem("fresh2u_tenant") || "";

    try {
      const result = await api("/api/v1/auth/me", "GET", null, token, tenant);
      const user = result?.data || null;
      if (!user?.role) return;

      setStatus(
        "loginStatus",
        `已检测到登录身份：${user.display_name || user.email || user.id}（${user.role}）。首页保持展示，不会自动跳转。`,
        "success"
      );
    } catch (_) {
      clearAuth();
    }
  }

  function bindLoginForm() {
    const form = byId("loginForm");
    if (!form) return;

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setStatus("loginStatus", "登录中，请稍候...");

      const email = byId("loginEmail")?.value?.trim() || "";
      const password = byId("loginPassword")?.value || "";
      const tenantInput = byId("loginTenant")?.value?.trim() || "";

      try {
        const result = await api("/api/v1/auth/login", "POST", { email, password });
        const token = result?.data?.token || "";
        const user = result?.data?.user || null;
        const tenant = tenantInput || user?.tenant_merchant_id || "";

        saveAuth(token, tenant, user);
        setStatus("loginStatus", "登录成功，正在进入角色工作台...", "success");

        window.setTimeout(() => {
          window.location.href = roleTarget(user?.role || "");
        }, 220);
      } catch (error) {
        setStatus("loginStatus", error.message || "登录失败", "error");
      }
    });
  }

  function bindRegisterForm() {
    const form = byId("registerForm");
    if (!form) return;

    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      setStatus("registerStatus", "注册中，请稍候...");

      const payload = {
        email: byId("registerEmail")?.value?.trim() || "",
        password: byId("registerPassword")?.value || "",
        role: byId("registerRole")?.value || "customer",
        display_name: byId("registerName")?.value?.trim() || "",
        tenant_merchant_id: byId("registerTenant")?.value?.trim() || null,
        bound_leader_user_id: byId("registerBoundLeader")?.value?.trim() || null,
      };

      try {
        await api("/api/v1/auth/register", "POST", payload);
        setStatus("registerStatus", "注册成功，请使用新账号登录。", "success");
      } catch (error) {
        setStatus("registerStatus", error.message || "注册失败", "error");
      }
    });
  }

  function bindRoleLinks() {
    const links = document.querySelectorAll("[data-role-link]");
    links.forEach((item) => {
      item.addEventListener("click", () => {
        const role = item.getAttribute("data-role-link") || "";
        window.location.href = roleTarget(role);
      });
    });
  }

  function bindRevealMotion() {
    const nodes = document.querySelectorAll("[data-reveal]");
    if (!nodes.length) return;

    const observer = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (entry.isIntersecting) {
            entry.target.classList.add("is-visible");
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.22 }
    );

    nodes.forEach((node, idx) => {
      node.style.transitionDelay = `${Math.min(idx * 70, 320)}ms`;
      observer.observe(node);
    });
  }

  document.addEventListener("DOMContentLoaded", async () => {
    bindLoginForm();
    bindRegisterForm();
    bindRoleLinks();
    bindRevealMotion();
    await showSessionHintIfLoggedIn();
  });
})();