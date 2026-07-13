const API_BASE = 'http://127.0.0.1:8000/api';
const UMBRAL_STOCK_BAJO = 5;
const ESTADOS_REPARACION = {
    pendiente: 'Pendiente',
    en_proceso: 'En proceso',
    listo: 'Listo para entregar',
    entregado: 'Entregado',
};

let sesionActual = null;
let reparacionEditando = null;
let sucursalesCache = [];

const TAMANO_PAGINA = 15;
let inventarioCompleto = [];
let inventarioPagina = 1;
let ventasCompleto = [];
let ventasPagina = 1;
let reparacionesCompleto = [];
let reparacionesPagina = 1;
let usuariosCompleto = [];
let usuariosPagina = 1;
let cierresCompleto = [];
let cierresPagina = 1;

// Ejecuta una acción async mostrando texto de "cargando" en el botón y deshabilitándolo mientras dura, para evitar doble clic.
async function conBotonCargando(btn, textoCargando, accion) {
    const htmlOriginal = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = textoCargando;
    try {
        await accion();
    } finally {
        btn.disabled = false;
        btn.innerHTML = htmlOriginal;
    }
}

// Alterna un input de contraseña entre oculto/visible (el "ojito"), reutilizable en cualquier campo de password.
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const oculto = input.type === 'password';
    input.type = oculto ? 'text' : 'password';
    btn.setAttribute('aria-pressed', String(oculto));
    btn.setAttribute('aria-label', oculto ? 'Ocultar contraseña' : 'Mostrar contraseña');
    btn.innerHTML = oculto
        ? '<i class="fas fa-eye-slash" aria-hidden="true"></i>'
        : '<i class="fas fa-eye" aria-hidden="true"></i>';
}

// true si hay sesión activa y es de tipo admin. Es la base de todos los permisos del lado del frontend.
function esAdmin() {
    return !!sesionActual && sesionActual.tipo === 'admin';
}

// Recorta un arreglo a los items que corresponden a una página (según TAMANO_PAGINA).
function paginar(items, pagina) {
    const inicio = (pagina - 1) * TAMANO_PAGINA;
    return items.slice(inicio, inicio + TAMANO_PAGINA);
}

// Dibuja los botones Anterior/Siguiente + "Página X de Y" para cualquier lista paginada.
function renderPaginacion(contenedorId, totalItems, paginaActual, irAPagina) {
    const contenedor = document.getElementById(contenedorId);
    contenedor.innerHTML = '';
    const totalPaginas = Math.max(1, Math.ceil(totalItems / TAMANO_PAGINA));
    if (totalPaginas <= 1) return;

    const nav = document.createElement('div');
    nav.className = 'paginacion';

    const btnAnt = document.createElement('button');
    btnAnt.type = 'button';
    btnAnt.className = 'back-btn';
    btnAnt.disabled = paginaActual <= 1;
    btnAnt.innerHTML = '<i class="fas fa-chevron-left" aria-hidden="true"></i> Anterior';
    btnAnt.addEventListener('click', () => irAPagina(paginaActual - 1));

    const info = document.createElement('span');
    info.textContent = `Página ${paginaActual} de ${totalPaginas}`;

    const btnSig = document.createElement('button');
    btnSig.type = 'button';
    btnSig.disabled = paginaActual >= totalPaginas;
    btnSig.innerHTML = 'Siguiente <i class="fas fa-chevron-right" aria-hidden="true"></i>';
    btnSig.addEventListener('click', () => irAPagina(paginaActual + 1));

    nav.append(btnAnt, info, btnSig);
    contenedor.appendChild(nav);
}

// Crea un <option> de select con el value/texto dados.
function crearOpcion(valor, texto) {
    const opt = document.createElement('option');
    opt.value = valor;
    opt.textContent = texto;
    return opt;
}

// Trae la lista de sucursales desde la API y llena todos los <select> que dependen de ella.
async function cargarSucursales() {
    try {
        sucursalesCache = await apiFetch('/sucursales');
    } catch (err) {
        sucursalesCache = [];
    }
    poblarSelectoresSucursal();
}

// Llena con sucursalesCache los distintos <select> de sucursal de toda la app (registro, sucursal activa, filtros).
function poblarSelectoresSucursal() {
    ['reg-sucursal', 'usu-sucursal'].forEach(id => {
        const sel = document.getElementById(id);
        const valorPrevio = sel.value;
        sel.innerHTML = '';
        sel.appendChild(crearOpcion('', '-- Selecciona sucursal --'));
        sucursalesCache.forEach(s => sel.appendChild(crearOpcion(s.id, s.nombre)));
        sel.value = valorPrevio;
    });

    ['inv-sucursal-activa', 'v-sucursal-activa', 'r-sucursal-activa'].forEach(id => {
        const sel = document.getElementById(id);
        const valorPrevio = sel.value;
        sel.innerHTML = '';
        sucursalesCache.forEach(s => sel.appendChild(crearOpcion(s.id, s.nombre)));
        sel.value = valorPrevio || (sucursalesCache[0] ? String(sucursalesCache[0].id) : '');
    });

    ['reportes-sucursal', 'cierre-sucursal', 'papelera-sucursal'].forEach(id => {
        const sel = document.getElementById(id);
        const valorPrevio = sel.value;
        sel.innerHTML = '';
        sel.appendChild(crearOpcion('', 'Todas las sucursales'));
        sucursalesCache.forEach(s => sel.appendChild(crearOpcion(s.id, s.nombre)));
        sel.value = valorPrevio;
    });
}

// Lee el token de sesión guardado en localStorage.
function getToken() {
    return localStorage.getItem('rj_token');
}

// Guarda el token y los datos del usuario en localStorage al iniciar sesión.
function guardarSesion(token, user) {
    localStorage.setItem('rj_token', token);
    localStorage.setItem('rj_user', JSON.stringify(user));
}

// Borra el token y los datos del usuario de localStorage (logout real).
function limpiarSesion() {
    localStorage.removeItem('rj_token');
    localStorage.removeItem('rj_user');
}

// Wrapper de fetch() para toda la API: agrega el header Authorization con el token,
// serializa el body a JSON (salvo que sea FormData), y convierte cualquier respuesta
// no-OK en un Error con .message y .status legibles por quien lo llama.
async function apiFetch(path, options = {}) {
    const headers = { Accept: 'application/json', ...(options.headers || {}) };
    const token = getToken();
    if (token) headers['Authorization'] = 'Bearer ' + token;

    let body = options.body;
    if (body && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(body);
    }

    const res = await fetch(API_BASE + path, { ...options, headers, body });

    if (!res.ok) {
        let message = 'Error de conexión con el servidor.';
        try {
            const data = await res.json();
            if (data.errors) {
                message = Object.values(data.errors).flat().join(' ');
            } else if (data.message) {
                message = data.message;
            }
        } catch (e) { /* respuesta sin cuerpo JSON */ }
        const error = new Error(message);
        error.status = res.status;
        throw error;
    }

    if (res.status === 204) return null;
    return res.json();
}

// Router simple: oculta todas las .screen y muestra la pedida, disparando la carga de datos de esa sección si aplica.
function navigateTo(id) {
    document.querySelectorAll('.screen').forEach(el => el.classList.add('hidden'));
    document.getElementById(id).classList.remove('hidden');

    if (id === 'sec-inventario') cargarInventario();
    if (id === 'sec-ventas') { cargarVentas(); cargarInventarioParaVentas(); }
    if (id === 'sec-reparaciones') cargarReparaciones();
    if (id === 'sec-reportes') cargarReportes();
    if (id === 'sec-sucursales') cargarSucursalesAdmin();
    if (id === 'sec-usuarios') cargarUsuariosAdmin();
    if (id === 'sec-cierres') cargarCierres();
    if (id === 'sec-mantenimiento') { cargarPapelera(); cargarSolicitudesPassword(); }
}

// Muestra/oculta en el DOM los botones y controles exclusivos de admin, según esAdmin().
function aplicarPermisos() {
    document.getElementById('reportes-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('sucursales-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('usuarios-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('cierres-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('mantenimiento-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('inventario-admin-controls').classList.toggle('hidden', !esAdmin());
    document.getElementById('th-inv-costo').classList.toggle('hidden', !esAdmin());
    document.getElementById('v-sucursal-activa').classList.toggle('hidden', !esAdmin());
    document.getElementById('r-sucursal-activa').classList.toggle('hidden', !esAdmin());
}

// Se llama justo después de iniciar sesión (o restaurarla): guarda el usuario actual, aplica permisos y navega al dashboard.
function mostrarDashboard(user) {
    document.getElementById('cargando-sesion').classList.add('hidden');
    sesionActual = user;
    let bienvenida = "Bienvenido/a, " + user.username;
    if (user.sucursal) bienvenida += ' — ' + user.sucursal.nombre;
    document.getElementById('msg-bienvenida').innerText = bienvenida;
    aplicarPermisos();
    navigateTo('sec-dashboard');
    cargarAlertas();
}

// Arma el texto de alertas del dashboard (stock bajo, reparaciones pendientes y, si es admin, solicitudes de contraseña).
async function cargarAlertas() {
    const el = document.getElementById('alertas-texto');
    try {
        const pedidos = [apiFetch('/inventario'), apiFetch('/reparaciones')];
        if (esAdmin()) pedidos.push(apiFetch('/solicitudes-password'));
        const [inventario, reparaciones, solicitudes] = await Promise.all(pedidos);
        const stockBajo = inventario.filter(i => i.cantidad <= UMBRAL_STOCK_BAJO).length;
        const pendientes = reparaciones.filter(r => r.estado !== 'entregado').length;
        const partes = [];
        if (pendientes) partes.push(pendientes + ' reparación(es) pendiente(s) de entrega');
        if (stockBajo) partes.push(stockBajo + ' producto(s) con inventario bajo');
        if (solicitudes && solicitudes.length) partes.push(solicitudes.length + ' solicitud(es) de recuperación de contraseña');
        el.textContent = partes.length ? 'Alertas: ' + partes.join('. ') + '.' : 'Sin alertas pendientes.';
    } catch (err) {
        el.textContent = 'No se pudieron cargar las alertas.';
    }
}

// Se ejecuta al cargar la página: si hay un token guardado, intenta restaurar la sesión
// pidiendo el usuario actual a la API. Solo borra el token en un 401 real; cualquier otro
// error (ej. backend caído un momento) muestra un aviso de "reintentar" en vez de cerrar sesión.
async function restaurarSesion() {
    if (!getToken()) return;

    // Si ya hay un token guardado, no mostramos el formulario de login ni
    // por un instante: eso es lo que se sentía como "me saca al login" cada
    // vez que la página se recargaba (por ejemplo, por el auto-reload de
    // Live Server) aunque la sesión siguiera siendo válida.
    document.getElementById('sec-login').classList.add('hidden');
    document.getElementById('cargando-sesion').classList.remove('hidden');

    try {
        const user = await apiFetch('/user');
        localStorage.setItem('rj_user', JSON.stringify(user));
        mostrarDashboard(user);
    } catch (err) {
        // Solo cerramos sesión (borramos el token) y mostramos el login si el
        // servidor de verdad rechazó el token (401). Cualquier otro error
        // (backend no disponible en ese momento, red intermitente, un tab que
        // el navegador "despertó" y todavía no reconecta) NO borra un token
        // que sigue siendo válido, y tampoco debe mostrar el formulario de
        // login — eso es justamente lo que se sentía como "estoy en un módulo
        // y de repente me manda al login" sin haber cerrado sesión de verdad.
        // En su lugar, se ofrece un botón para reintentar sin perder el token.
        if (err.status === 401) {
            limpiarSesion();
            document.getElementById('cargando-sesion').classList.add('hidden');
            document.getElementById('sec-login').classList.remove('hidden');
        } else {
            mostrarErrorConexionSesion();
        }
    }
}

// Reemplaza la pantalla de "Cargando sesión..." por un aviso con botón "Reintentar" cuando restaurarSesion() falla por conexión (no por token inválido).
function mostrarErrorConexionSesion() {
    const el = document.getElementById('cargando-sesion');
    el.innerHTML = '';
    const p = document.createElement('p');
    p.textContent = 'No se pudo conectar con el servidor. Tu sesión sigue activa, solo reintenta.';
    p.setAttribute('role', 'alert');
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.style.width = 'auto';
    btn.style.margin = '10px auto';
    btn.textContent = 'Reintentar';
    btn.addEventListener('click', () => {
        el.textContent = 'Cargando sesión...';
        restaurarSesion();
    });
    el.append(p, btn);
}

// Valida el formulario de registro (campos completos, contraseñas iguales) y crea la cuenta vía POST /register.
async function ejecutarRegistro() {
    const nombre = document.getElementById('reg-nombre').value;
    const nuevoUser = document.getElementById('reg-user').value;
    const email = document.getElementById('reg-email').value;
    const nuevoPass = document.getElementById('reg-pass').value;
    const passConfirm = document.getElementById('reg-pass-confirm').value;
    const sucursalId = document.getElementById('reg-sucursal').value;
    const errorEl = document.getElementById('reg-error');
    errorEl.classList.add('hidden');

    if (nuevoUser === "" || email === "" || nuevoPass === "" || !sucursalId) {
        errorEl.textContent = 'Por favor rellena los campos, incluyendo el correo y la sucursal.';
        errorEl.classList.remove('hidden');
        return;
    }
    if (nuevoPass !== passConfirm) {
        errorEl.textContent = 'Las contraseñas no coinciden.';
        errorEl.classList.remove('hidden');
        return;
    }
    try {
        await apiFetch('/register', { method: 'POST', body: { name: nombre, username: nuevoUser, email, password: nuevoPass, sucursal_id: sucursalId } });
        alert('¡Usuario ' + nuevoUser + ' registrado con éxito!');
        navigateTo('sec-login');
    } catch (err) {
        errorEl.textContent = err.message || 'No se pudo registrar el usuario.';
        errorEl.classList.remove('hidden');
    }
}

// Envía usuario/contraseña a POST /login; si funciona guarda la sesión y muestra el dashboard, si no muestra el error real (credenciales, límite de intentos, etc).
async function login() {
    const eInput = document.getElementById('login-email').value;
    const pInput = document.getElementById('password').value;

    const errorEl = document.getElementById('login-error');
    try {
        const data = await apiFetch('/login', { method: 'POST', body: { email: eInput, password: pInput } });
        errorEl.classList.add('hidden');
        guardarSesion(data.token, data.user);
        mostrarDashboard(data.user);
    } catch (err) {
        document.getElementById('login-error-texto').textContent = err.status === 429
            ? 'Demasiados intentos. Espera un minuto y vuelve a intentar.'
            : (err.message || 'Credenciales incorrectas.');
        errorEl.classList.remove('hidden');
    }
}

// Cierra sesión: borra el token local de inmediato (sin esperar al servidor) y vuelve al login.
function logout() {
    // No se espera la respuesta del servidor: cerrar sesión localmente (borrar
    // el token guardado) es lo que de verdad importa para el usuario y no
    // depende de la red. Revocar el token en el servidor es solo un plus (evita
    // que ese token viejo se pueda reusar si alguien lo llegara a tener) y no
    // debe hacer que "Salir" se sienta lento esperando ese viaje de ida y vuelta.
    apiFetch('/logout', { method: 'POST' }).catch(() => { /* token ya inválido o red caída, no importa */ });
    sesionActual = null;
    limpiarSesion();
    document.getElementById('login-email').value = '';
    document.getElementById('password').value = '';
    navigateTo('sec-login');
}

// Envía el formulario de "olvidé mi contraseña" (solo el username) a la API.
async function recuperarAcceso(event) {
    event.preventDefault();
    const username = document.getElementById('rec-usuario').value.trim();
    if (!username) return;
    const resEl = document.getElementById('rec-resultado');
    try {
        const data = await apiFetch('/olvide-password', { method: 'POST', body: { username } });
        resEl.textContent = data.message;
        document.getElementById('form-recuperar').reset();
    } catch (err) {
        resEl.textContent = err.message || 'No se pudo enviar la solicitud.';
    }
}

// Busca reparaciones por cliente o id (pantalla pública de Consulta de Estado) y pinta los resultados.
async function consultarEstado(event) {
    event.preventDefault();
    const q = document.getElementById('c-id').value;
    const resEl = document.getElementById('res-c');
    resEl.textContent = 'Buscando...';
    try {
        const reps = await apiFetch('/reparaciones?buscar=' + encodeURIComponent(q));
        renderResultadosConsulta(reps, q);
    } catch (err) {
        resEl.textContent = 'No se pudo consultar el estado.';
    }
}

// Dibuja la lista de reparaciones encontradas por consultarEstado(), con foto, datos y estado.
function renderResultadosConsulta(reps, q) {
    const resEl = document.getElementById('res-c');
    resEl.innerHTML = '';

    if (!reps.length) {
        resEl.textContent = `No se encontró ninguna reparación para "${q}".`;
        return;
    }

    const resumen = document.createElement('p');
    resumen.style.fontWeight = 'bold';
    resumen.textContent = reps.length === 1 ? '1 resultado encontrado:' : reps.length + ' resultados encontrados:';
    resEl.appendChild(resumen);

    const ul = document.createElement('ul');
    reps.forEach(r => {
        const li = document.createElement('li');
        const contenido = document.createElement('div');
        contenido.className = 'li-content';

        if (r.foto_url) {
            const img = document.createElement('img');
            img.src = r.foto_url;
            img.className = 'img-preview';
            img.alt = 'Foto del reloj de ' + r.cliente;
            contenido.appendChild(img);
        }

        const texto = document.createElement('span');
        const lId = document.createElement('strong'); lId.textContent = 'ID:';
        const lCliente = document.createElement('strong'); lCliente.textContent = 'Cliente:';
        const lReloj = document.createElement('strong'); lReloj.textContent = 'Reloj:';
        texto.append(
            lId, document.createTextNode(' ' + r.id + ' | '),
            lCliente, document.createTextNode(' ' + r.cliente + (r.cedula ? ' — C.C. ' + r.cedula : '') + (r.telefono ? ' (' + r.telefono + ')' : '') + ' | '),
            lReloj, document.createTextNode(' ' + (r.modelo || '(sin especificar)')),
            document.createElement('br'),
            document.createTextNode(`Total: $${r.valor_total} | Abono: $${r.abono} | `)
        );
        const saldoSpan = document.createElement('span');
        saldoSpan.style.color = 'red';
        saldoSpan.textContent = `Saldo: $${r.saldo}`;
        texto.appendChild(saldoSpan);
        texto.appendChild(document.createElement('br'));

        const badge = document.createElement('span');
        badge.className = 'badge badge-' + r.estado;
        badge.textContent = ESTADOS_REPARACION[r.estado] || r.estado;
        texto.appendChild(badge);

        let extra = '';
        if (r.fecha) extra += ' — Fecha: ' + r.fecha.slice(0, 10);
        if (r.sucursal) extra += ' — Sucursal: ' + r.sucursal.nombre;
        if (r.user) extra += ' — Registrado por: ' + r.user.username;
        if (extra) texto.appendChild(document.createTextNode(extra));

        if (r.observaciones) {
            texto.appendChild(document.createElement('br'));
            const lObs = document.createElement('strong'); lObs.textContent = 'Observaciones:';
            texto.append(lObs, document.createTextNode(' ' + r.observaciones));
        }

        contenido.appendChild(texto);
        li.appendChild(contenido);
        ul.appendChild(li);
    });
    resEl.appendChild(ul);
}

// Crea un botón de "Eliminar" (icono de basura) reutilizable, con su etiqueta accesible y su acción de click.
function crearBotonEliminar(etiqueta, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'delete-btn';
    btn.setAttribute('aria-label', etiqueta);
    btn.innerHTML = '<i class="fas fa-trash" aria-hidden="true"></i>';
    btn.addEventListener('click', onClick);
    return btn;
}

// Crea un botón de "Editar" (icono de lápiz) reutilizable, con su etiqueta accesible y su acción de click.
function crearBotonEditar(etiqueta, onClick) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'edit-btn';
    btn.setAttribute('aria-label', etiqueta);
    btn.innerHTML = '<i class="fas fa-pen" aria-hidden="true"></i>';
    btn.addEventListener('click', onClick);
    return btn;
}

/* ---------- Inventario ---------- */

// Trae el inventario desde la API (filtrado por sucursal si es admin y eligió una) y lo pinta en la tabla.
async function cargarInventario() {
    try {
        let path = '/inventario';
        if (esAdmin()) {
            const sucursalId = document.getElementById('inv-sucursal-activa').value;
            if (sucursalId) path += '?sucursal_id=' + sucursalId;
        }
        inventarioCompleto = await apiFetch(path);
        inventarioPagina = 1;
        renderInventario();
    } catch (err) { /* deja la tabla como estaba si falla la carga */ }
}

// Dibuja la tabla de Inventario: aplica el filtro de búsqueda, pagina, y agrega la columna Costo y los botones Editar/Eliminar solo si es admin.
function renderInventario() {
    const q = document.getElementById('busc-inv').value.toLowerCase();
    const filtrados = q
        ? inventarioCompleto.filter(item => (item.codigo + ' ' + item.descripcion).toLowerCase().includes(q))
        : inventarioCompleto;

    const tbody = document.querySelector('#tab-inv tbody');
    tbody.innerHTML = '';
    paginar(filtrados, inventarioPagina).forEach(item => {
        const tr = document.createElement('tr');

        const tdFoto = document.createElement('td');
        if (item.foto_url) {
            const img = document.createElement('img');
            img.src = item.foto_url;
            img.className = 'thumb-inv';
            img.alt = 'Foto de ' + item.descripcion;
            tdFoto.appendChild(img);
        }
        const tdCodigo = document.createElement('td'); tdCodigo.textContent = item.codigo;
        const tdDesc = document.createElement('td'); tdDesc.textContent = item.descripcion;
        const tdCant = document.createElement('td'); tdCant.textContent = item.cantidad;
        const tdPrecio = document.createElement('td'); tdPrecio.textContent = '$' + item.precio;
        const tdAcciones = document.createElement('td'); tdAcciones.className = 'acciones';

        if (esAdmin()) {
            const grupo = document.createElement('div');
            grupo.className = 'acciones-grupo';
            grupo.appendChild(crearBotonEditar('Editar ' + item.descripcion, () => editarProducto(item)));
            grupo.appendChild(crearBotonEliminar('Eliminar ' + item.descripcion, () => eliminarProducto(item.id)));
            tdAcciones.appendChild(grupo);
        }

        // La columna Costo solo se agrega para admin: el backend ya no manda
        // "costo" a empleados (dato de margen sensible), y aunque lo mandara,
        // la cabecera está oculta para ellos vía aplicarPermisos().
        if (esAdmin()) {
            const tdCosto = document.createElement('td'); tdCosto.textContent = '$' + (item.costo ?? 0);
            tr.append(tdFoto, tdCodigo, tdDesc, tdCant, tdPrecio, tdCosto, tdAcciones);
        } else {
            tr.append(tdFoto, tdCodigo, tdDesc, tdCant, tdPrecio, tdAcciones);
        }
        tbody.appendChild(tr);
    });

    renderPaginacion('inv-paginacion', filtrados.length, inventarioPagina, pagina => {
        inventarioPagina = pagina;
        renderInventario();
    });
}

// Precarga el formulario de Inventario con los datos del producto elegido, para editarlo.
function editarProducto(item) {
    document.getElementById('inv-edit-id').value = item.id;
    document.getElementById('inv-codigo').value = item.codigo;
    document.getElementById('inv-desc').value = item.descripcion;
    document.getElementById('inv-cant').value = item.cantidad;
    document.getElementById('inv-precio').value = item.precio;
    document.getElementById('inv-costo').value = item.costo ?? '';
    document.getElementById('inv-foto').value = '';
    document.getElementById('inv-submit-btn').innerHTML = '<i class="fas fa-save" aria-hidden="true"></i> Guardar Cambios';
    document.getElementById('inv-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Inventario y lo vuelve al modo "Agregar producto" (sale del modo edición).
function cancelarEdicionProducto() {
    document.getElementById('form-inventario').reset();
    document.getElementById('inv-edit-id').value = '';
    document.getElementById('inv-submit-btn').innerHTML = '<i class="fas fa-plus" aria-hidden="true"></i> Agregar Producto';
    document.getElementById('inv-cancelar-btn').classList.add('hidden');
}

// Envía el formulario de Inventario (crea o edita según haya un id en edición), como multipart/form-data por la foto.
async function agregarProducto() {
    const codigo = document.getElementById('inv-codigo').value.trim();
    const descripcion = document.getElementById('inv-desc').value.trim();
    const cantidad = document.getElementById('inv-cant').value;
    const precio = document.getElementById('inv-precio').value;
    const costo = document.getElementById('inv-costo').value;
    if (!codigo || !descripcion) {
        alert('Completa código y descripción');
        return;
    }

    const editId = document.getElementById('inv-edit-id').value;
    const formData = new FormData();
    formData.append('sucursal_id', document.getElementById('inv-sucursal-activa').value);
    formData.append('codigo', codigo);
    formData.append('descripcion', descripcion);
    formData.append('cantidad', cantidad || 0);
    formData.append('precio', precio || 0);
    formData.append('costo', costo || 0);
    const fotoInput = document.getElementById('inv-foto');
    if (fotoInput.files && fotoInput.files[0]) formData.append('foto', fotoInput.files[0]);

    await conBotonCargando(document.getElementById('inv-submit-btn'), '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Guardando...', async () => {
        try {
            if (editId) {
                formData.append('_method', 'PUT');
                await apiFetch('/inventario/' + editId, { method: 'POST', body: formData });
            } else {
                await apiFetch('/inventario', { method: 'POST', body: formData });
            }
            cancelarEdicionProducto();
            cargarInventario();
        } catch (err) {
            alert(err.message || 'No se pudo guardar el producto.');
        }
    });
}

// Pide confirmación y borra (soft delete) un producto del inventario.
async function eliminarProducto(id) {
    if (!confirm('¿Eliminar este producto?')) return;
    try {
        await apiFetch('/inventario/' + id, { method: 'DELETE' });
        cargarInventario();
    } catch (err) {
        alert('No se pudo eliminar el producto.');
    }
}

// Se dispara al escribir en el buscador de Inventario: resetea a la página 1 y vuelve a dibujar la tabla filtrada.
function buscInv() {
    inventarioPagina = 1;
    renderInventario();
}

/* ---------- Ventas ---------- */

// Trae las ventas desde la API y las pinta en la tabla.
async function cargarVentas() {
    try {
        ventasCompleto = await apiFetch('/ventas');
        ventasPagina = 1;
        renderVentas();
    } catch (err) { /* deja la lista como estaba si falla la carga */ }
}

// Llena el <select> de "producto del inventario" en el formulario de Ventas, solo con productos que tengan stock.
async function cargarInventarioParaVentas() {
    const select = document.getElementById('v-inv-select');
    try {
        let path = '/inventario';
        if (esAdmin()) {
            const sucursalId = document.getElementById('v-sucursal-activa').value;
            if (sucursalId) path += '?sucursal_id=' + sucursalId;
        }
        const items = await apiFetch(path);
        const seleccionActual = select.value;
        select.innerHTML = '<option value="">-- Producto libre (no descuenta inventario) --</option>';
        items.filter(i => i.cantidad > 0).forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            opt.textContent = `${item.descripcion} (stock: ${item.cantidad}, $${item.precio})`;
            opt.dataset.descripcion = item.descripcion;
            opt.dataset.precio = item.precio;
            opt.dataset.stock = item.cantidad;
            select.appendChild(opt);
        });
        select.value = seleccionActual;
    } catch (err) { /* deja el select como estaba si falla la carga */ }
}

// Al elegir un producto del inventario en Ventas, autocompleta descripción y precio en el formulario.
function seleccionarProductoVenta() {
    const select = document.getElementById('v-inv-select');
    const opt = select.selectedOptions[0];
    if (!opt || !opt.value) {
        document.getElementById('v-inventario-id').value = '';
        return;
    }
    document.getElementById('v-inventario-id').value = opt.value;
    document.getElementById('v-prod').value = opt.dataset.descripcion;
    document.getElementById('v-val').value = opt.dataset.precio;
    if (!document.getElementById('v-cant').value) document.getElementById('v-cant').value = 1;
}

// Dibuja la tabla de Ventas, con botones Editar/Eliminar solo para admin.
function renderVentas() {
    const tbody = document.querySelector('#tab-ventas tbody');
    tbody.innerHTML = '';
    paginar(ventasCompleto, ventasPagina).forEach(v => {
        const tr = document.createElement('tr');
        const tdProd = document.createElement('td'); tdProd.textContent = v.producto;
        const tdCant = document.createElement('td'); tdCant.textContent = v.cantidad ?? '';
        const tdValor = document.createElement('td'); tdValor.textContent = v.valor ? '$' + v.valor : '';
        const tdUser = document.createElement('td'); tdUser.textContent = v.user ? v.user.username : '';
        const tdAcciones = document.createElement('td'); tdAcciones.className = 'acciones';

        if (esAdmin()) {
            const grupo = document.createElement('div');
            grupo.className = 'acciones-grupo';
            grupo.appendChild(crearBotonEditar('Editar venta de ' + v.producto, () => editarVenta(v)));
            grupo.appendChild(crearBotonEliminar('Eliminar venta de ' + v.producto, () => eliminarVenta(v.id)));
            tdAcciones.appendChild(grupo);
        }

        tr.append(tdProd, tdCant, tdValor, tdUser, tdAcciones);
        tbody.appendChild(tr);
    });

    renderPaginacion('ventas-paginacion', ventasCompleto.length, ventasPagina, pagina => {
        ventasPagina = pagina;
        renderVentas();
    });
}

// Precarga el formulario de Ventas con los datos de la venta elegida.
function editarVenta(v) {
    document.getElementById('v-edit-id').value = v.id;
    document.getElementById('v-inventario-id').value = '';
    document.getElementById('v-inv-select').value = '';
    document.getElementById('v-prod').value = v.producto;
    document.getElementById('v-cant').value = v.cantidad || '';
    document.getElementById('v-val').value = v.valor || '';
    document.getElementById('v-submit-btn').textContent = 'Guardar Cambios';
    document.getElementById('v-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Ventas y sale del modo edición.
function cancelarEdicionVenta() {
    document.getElementById('form-ventas').reset();
    document.getElementById('v-edit-id').value = '';
    document.getElementById('v-submit-btn').textContent = 'Guardar Venta';
    document.getElementById('v-cancelar-btn').classList.add('hidden');
}

// Envía el formulario de Ventas (crea o edita según corresponda).
async function regVenta() {
    const p = document.getElementById('v-prod').value.trim();
    if (!p) return;
    const cantidad = document.getElementById('v-cant').value;
    const valor = document.getElementById('v-val').value;
    const editId = document.getElementById('v-edit-id').value;
    const inventarioId = document.getElementById('v-inventario-id').value;

    await conBotonCargando(document.getElementById('v-submit-btn'), 'Guardando...', async () => {
        try {
            if (editId) {
                await apiFetch('/ventas/' + editId, { method: 'PATCH', body: { producto: p, cantidad, valor } });
                cancelarEdicionVenta();
            } else {
                const body = { producto: p, cantidad, valor };
                if (inventarioId) body.inventario_id = inventarioId;
                if (esAdmin()) body.sucursal_id = document.getElementById('v-sucursal-activa').value;
                await apiFetch('/ventas', { method: 'POST', body });
                document.getElementById('form-ventas').reset();
                alert('Venta guardada');
            }
            await cargarVentas();
            if (inventarioId) await cargarInventarioParaVentas();
        } catch (err) {
            alert(err.message || 'No se pudo guardar la venta.');
        }
    });
}

// Pide confirmación y borra una venta (restaura el stock si estaba ligada a un producto).
async function eliminarVenta(id) {
    if (!confirm('¿Eliminar esta venta?')) return;
    try {
        await apiFetch('/ventas/' + id, { method: 'DELETE' });
        cargarVentas();
        cargarInventarioParaVentas();
    } catch (err) {
        alert('No se pudo eliminar la venta.');
    }
}

/* ---------- Reparaciones ---------- */

// Trae las reparaciones desde la API y las pinta en la lista.
async function cargarReparaciones() {
    try {
        reparacionesCompleto = await apiFetch('/reparaciones');
        reparacionesPagina = 1;
        renderReparaciones();
    } catch (err) { /* deja la lista como estaba si falla la carga */ }
}

// Crea el <select> de estado (pendiente/en_proceso/listo/entregado) de una reparación, con su evento de cambio.
function crearSelectEstado(reparacion) {
    const select = document.createElement('select');
    select.className = 'estado-select';
    select.setAttribute('aria-label', 'Estado de la reparación de ' + reparacion.cliente);
    Object.entries(ESTADOS_REPARACION).forEach(([valor, etiqueta]) => {
        const opt = document.createElement('option');
        opt.value = valor;
        opt.textContent = etiqueta;
        if (valor === reparacion.estado) opt.selected = true;
        select.appendChild(opt);
    });
    select.addEventListener('change', () => cambiarEstadoReparacion(reparacion, select.value));
    return select;
}

// Dibuja la lista de Reparaciones: foto, datos, saldo, selector de estado y campo de observaciones (editable por cualquier rol), y botones Editar/Eliminar solo para admin.
function renderReparaciones() {
    const filtro = document.getElementById('filtro-estado-rep').value;
    const filtrados = filtro ? reparacionesCompleto.filter(r => r.estado === filtro) : reparacionesCompleto;

    const ul = document.getElementById('lista-reps');
    ul.innerHTML = '';
    paginar(filtrados, reparacionesPagina).forEach(r => {
        const li = document.createElement('li');
        const contenido = document.createElement('div');
        contenido.className = 'li-content';

        if (r.foto_url) {
            const img = document.createElement('img');
            img.src = r.foto_url;
            img.className = 'img-preview';
            img.alt = 'Foto del reloj de ' + r.cliente;
            contenido.appendChild(img);
        }

        const texto = document.createElement('span');
        const lCliente = document.createElement('strong'); lCliente.textContent = 'Cliente:';
        const lReloj = document.createElement('strong'); lReloj.textContent = 'Reloj:';
        texto.append(
            lCliente, document.createTextNode(' ' + r.cliente + (r.cedula ? ' — C.C. ' + r.cedula : '') + (r.telefono ? ' (' + r.telefono + ')' : '') + ' | '),
            lReloj, document.createTextNode(' ' + (r.modelo || '')),
            document.createElement('br'),
            document.createTextNode(`Total: $${r.valor_total} | Abono: $${r.abono} | `)
        );
        const saldoSpan = document.createElement('span');
        saldoSpan.style.color = 'red';
        saldoSpan.textContent = `Saldo: $${r.saldo}`;
        texto.appendChild(saldoSpan);
        if (r.sucursal) texto.appendChild(document.createTextNode(' — Sede: ' + r.sucursal.nombre));
        if (r.user) texto.appendChild(document.createTextNode(' — registrado por ' + r.user.username));
        contenido.appendChild(texto);

        const obsWrap = document.createElement('div');
        obsWrap.className = 'obs-wrap';
        const obsLabel = document.createElement('label');
        obsLabel.className = 'sr-only';
        obsLabel.setAttribute('for', 'obs-' + r.id);
        obsLabel.textContent = 'Observaciones de la reparación de ' + r.cliente;
        const obsInput = document.createElement('textarea');
        obsInput.id = 'obs-' + r.id;
        obsInput.className = 'obs-input';
        obsInput.rows = 2;
        obsInput.placeholder = 'Observaciones (ej. diagnóstico al revisar el reloj)';
        obsInput.value = r.observaciones || '';
        const obsBtn = document.createElement('button');
        obsBtn.type = 'button';
        obsBtn.className = 'back-btn';
        obsBtn.textContent = 'Guardar notas';
        obsBtn.addEventListener('click', () => guardarObservaciones(r, obsInput.value));
        obsWrap.append(obsLabel, obsInput, obsBtn);
        contenido.appendChild(obsWrap);

        const acciones = document.createElement('div');
        acciones.className = 'li-acciones';
        const badge = document.createElement('span');
        badge.className = 'badge badge-' + r.estado;
        badge.textContent = ESTADOS_REPARACION[r.estado] || r.estado;
        acciones.appendChild(badge);
        acciones.appendChild(crearSelectEstado(r));
        if (esAdmin()) {
            acciones.appendChild(crearBotonEditar('Editar reparación de ' + r.cliente, () => editarReparacion(r)));
            acciones.appendChild(crearBotonEliminar('Eliminar reparación de ' + r.cliente, () => eliminarReparacion(r.id)));
        }

        li.append(contenido, acciones);
        ul.appendChild(li);
    });

    renderPaginacion('reps-paginacion', filtrados.length, reparacionesPagina, pagina => {
        reparacionesPagina = pagina;
        renderReparaciones();
    });
}

// Guarda el nuevo estado elegido en el select, reenviando también los demás campos que la validación de admin exige en el backend.
async function cambiarEstadoReparacion(reparacion, nuevoEstado) {
    try {
        await apiFetch('/reparaciones/' + reparacion.id, {
            method: 'PUT',
            body: {
                estado: nuevoEstado,
                observaciones: reparacion.observaciones || '',
                cliente: reparacion.cliente,
                modelo: reparacion.modelo,
                valor_total: reparacion.valor_total,
                abono: reparacion.abono,
                fecha: reparacion.fecha,
            },
        });
    } catch (err) {
        alert(err.message || 'No se pudo actualizar el estado.');
    }
    cargarReparaciones();
}

// A diferencia del resto de campos (editables solo por admin vía el formulario),
// observaciones la puede actualizar cualquiera que atienda la reparación, igual
// que el estado — por eso tiene su propio control inline en vez de requerir el
// botón "Editar" (que solo ven los admin).
// Guarda el texto de observaciones de una reparación (lo puede escribir cualquier rol).
async function guardarObservaciones(reparacion, texto) {
    try {
        await apiFetch('/reparaciones/' + reparacion.id, {
            method: 'PUT',
            body: {
                estado: reparacion.estado,
                observaciones: texto,
                cliente: reparacion.cliente,
                modelo: reparacion.modelo,
                valor_total: reparacion.valor_total,
                abono: reparacion.abono,
                fecha: reparacion.fecha,
            },
        });
    } catch (err) {
        alert(err.message || 'No se pudieron guardar las observaciones.');
        return;
    }
    cargarReparaciones();
}

// Precarga el formulario de Reparaciones con los datos del registro elegido (solo admin).
function editarReparacion(item) {
    reparacionEditando = item;
    document.getElementById('r-edit-id').value = item.id;
    document.getElementById('r-cli').value = item.cliente;
    document.getElementById('r-cedula').value = item.cedula || '';
    document.getElementById('r-tel').value = item.telefono || '';
    document.getElementById('r-mod').value = item.modelo || '';
    document.getElementById('r-val-total').value = item.valor_total;
    document.getElementById('r-abono').value = item.abono;
    document.getElementById('r-fec').value = item.fecha ? item.fecha.slice(0, 10) : '';
    document.getElementById('r-obs').value = item.observaciones || '';
    document.getElementById('r-foto').value = '';
    document.getElementById('r-submit-btn').textContent = 'Guardar Cambios';
    document.getElementById('r-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Reparaciones y sale del modo edición.
function cancelarEdicionReparacion() {
    reparacionEditando = null;
    document.getElementById('form-reparaciones').reset();
    document.getElementById('r-edit-id').value = '';
    document.getElementById('r-submit-btn').textContent = 'Registrar';
    document.getElementById('r-cancelar-btn').classList.add('hidden');
}

// Envía el formulario de Reparaciones (crea o edita), como multipart/form-data por la foto.
async function regRep() {
    const c = document.getElementById('r-cli').value.trim();
    if (!c) return;

    const editId = document.getElementById('r-edit-id').value;
    const formData = new FormData();
    formData.append('cliente', c);
    formData.append('cedula', document.getElementById('r-cedula').value);
    formData.append('telefono', document.getElementById('r-tel').value);
    formData.append('modelo', document.getElementById('r-mod').value);
    formData.append('valor_total', document.getElementById('r-val-total').value || 0);
    formData.append('abono', document.getElementById('r-abono').value || 0);
    const fecha = document.getElementById('r-fec').value;
    if (fecha) formData.append('fecha', fecha);
    formData.append('observaciones', document.getElementById('r-obs').value);
    const fotoInput = document.getElementById('r-foto');
    if (fotoInput.files && fotoInput.files[0]) {
        formData.append('foto', fotoInput.files[0]);
    }

    await conBotonCargando(document.getElementById('r-submit-btn'), 'Guardando...', async () => {
        try {
            if (editId) {
                formData.append('estado', reparacionEditando ? reparacionEditando.estado : 'pendiente');
                formData.append('_method', 'PUT');
                await apiFetch('/reparaciones/' + editId, { method: 'POST', body: formData });
                cancelarEdicionReparacion();
            } else {
                if (esAdmin()) formData.append('sucursal_id', document.getElementById('r-sucursal-activa').value);
                await apiFetch('/reparaciones', { method: 'POST', body: formData });
                document.getElementById('form-reparaciones').reset();
                alert('Reparación registrada');
            }
            await cargarReparaciones();
        } catch (err) {
            alert(err.message || 'No se pudo guardar la reparación.');
        }
    });
}

// Aplica el filtro de estado sobre la lista completa ya cargada y vuelve a paginar/dibujar.
function filtrarReparaciones() {
    reparacionesPagina = 1;
    renderReparaciones();
}

// Pide confirmación y borra (soft delete) una reparación (solo admin).
async function eliminarReparacion(id) {
    if (!confirm('¿Eliminar esta reparación?')) return;
    try {
        await apiFetch('/reparaciones/' + id, { method: 'DELETE' });
        cargarReparaciones();
    } catch (err) {
        alert('No se pudo eliminar la reparación.');
    }
}

/* ---------- Reportes ---------- */

// Trae las estadísticas agregadas (ventas, reparaciones, inventario) y las pinta en las tarjetas de Reportes; si no es admin, muestra un aviso en vez de la data.
async function cargarReportes() {
    const el = document.getElementById('reportes-contenido');
    if (!esAdmin()) {
        el.textContent = 'Esta sección es solo para el administrador.';
        return;
    }
    try {
        const periodo = document.getElementById('reportes-periodo').value;
        const sucursalId = document.getElementById('reportes-sucursal').value;
        let path = '/reportes?periodo=' + periodo;
        if (sucursalId) path += '&sucursal_id=' + sucursalId;
        const r = await apiFetch(path);
        el.innerHTML = '';
        const grid = document.createElement('div');
        grid.className = 'reportes-grid';
        const tarjetas = [
            ['Ventas totales', '$' + r.ventas.total + ' (' + r.ventas.cantidad + ')'],
            // Utilidad estimada: solo cuenta el costo de ventas ligadas a un producto
            // de Inventario (con costo registrado); las ventas de "producto libre"
            // quedan fuera del cálculo por no tener costo que restar.
            ['Utilidad estimada', '$' + r.ventas.utilidad_estimada.toFixed(2)],
            ['Reparaciones totales', r.reparaciones.total],
            ['Pendientes', r.reparaciones.pendiente],
            ['En proceso', r.reparaciones.en_proceso],
            ['Listas para entregar', r.reparaciones.listo],
            ['Entregadas', r.reparaciones.entregado],
            ['Saldo por cobrar', '$' + r.reparaciones.saldo_pendiente],
            ['Productos en inventario', r.inventario.productos],
            ['Valor del inventario', '$' + r.inventario.valor_total],
            ['Utilidad potencial en stock', '$' + (r.inventario.valor_total - r.inventario.costo_total).toFixed(2)],
            ['Productos con stock bajo', r.inventario.stock_bajo],
        ];
        tarjetas.forEach(([titulo, valor]) => {
            const card = document.createElement('div');
            card.className = 'reporte-card';
            const h3 = document.createElement('h3'); h3.textContent = titulo;
            const p = document.createElement('p'); p.textContent = valor;
            card.append(h3, p);
            grid.appendChild(card);
        });
        el.appendChild(grid);

        if (r.por_sucursal) {
            const tabla = document.createElement('table');
            const caption = document.createElement('caption');
            caption.textContent = 'Desglose por sucursal';
            tabla.appendChild(caption);
            const thead = document.createElement('thead');
            const trh = document.createElement('tr');
            ['Sucursal', 'Ventas', 'Reparaciones pendientes', 'Productos'].forEach(titulo => {
                const th = document.createElement('th');
                th.scope = 'col';
                th.textContent = titulo;
                trh.appendChild(th);
            });
            thead.appendChild(trh);
            tabla.appendChild(thead);
            const tbody = document.createElement('tbody');
            r.por_sucursal.forEach(s => {
                const tr = document.createElement('tr');
                [s.nombre, '$' + s.ventas_total, s.reparaciones_pendientes, s.productos].forEach(valor => {
                    const td = document.createElement('td');
                    td.textContent = valor;
                    tr.appendChild(td);
                });
                tbody.appendChild(tr);
            });
            tabla.appendChild(tbody);
            el.appendChild(tabla);
        }
    } catch (err) {
        el.textContent = 'No se pudieron cargar los reportes.';
    }
}

/* ---------- Sucursales ---------- */

// Trae las sucursales para la pantalla de administración (sec-sucursales) y las pinta en la lista.
async function cargarSucursalesAdmin() {
    try {
        renderSucursales(await apiFetch('/sucursales'));
    } catch (err) { /* deja la lista como estaba si falla la carga */ }
}

// Dibuja la lista de sucursales con su botón Editar.
function renderSucursales(items) {
    const ul = document.getElementById('lista-sucursales');
    ul.innerHTML = '';
    items.forEach(s => {
        const li = document.createElement('li');
        let texto = s.nombre;
        if (s.direccion) texto += ' — ' + s.direccion;
        if (s.telefono) texto += ' — ' + s.telefono;
        const span = document.createElement('span');
        span.textContent = texto;
        li.appendChild(span);

        const grupo = document.createElement('div');
        grupo.className = 'li-acciones';
        grupo.appendChild(crearBotonEditar('Editar ' + s.nombre, () => editarSucursal(s)));
        li.appendChild(grupo);

        ul.appendChild(li);
    });
}

// Precarga el formulario de Sucursales con los datos de la sucursal elegida.
function editarSucursal(s) {
    document.getElementById('suc-edit-id').value = s.id;
    document.getElementById('suc-nombre').value = s.nombre;
    document.getElementById('suc-direccion').value = s.direccion || '';
    document.getElementById('suc-telefono').value = s.telefono || '';
    document.getElementById('suc-submit-btn').innerHTML = '<i class="fas fa-save" aria-hidden="true"></i> Guardar Cambios';
    document.getElementById('suc-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Sucursales y sale del modo edición.
function cancelarEdicionSucursal() {
    document.getElementById('form-sucursal').reset();
    document.getElementById('suc-edit-id').value = '';
    document.getElementById('suc-submit-btn').innerHTML = '<i class="fas fa-plus" aria-hidden="true"></i> Agregar Sucursal';
    document.getElementById('suc-cancelar-btn').classList.add('hidden');
}

// Envía el formulario de Sucursales (crea o edita).
async function guardarSucursal() {
    const nombre = document.getElementById('suc-nombre').value.trim();
    if (!nombre) return;
    const body = {
        nombre,
        direccion: document.getElementById('suc-direccion').value,
        telefono: document.getElementById('suc-telefono').value,
    };
    const editId = document.getElementById('suc-edit-id').value;

    try {
        if (editId) {
            await apiFetch('/sucursales/' + editId, { method: 'PUT', body });
        } else {
            await apiFetch('/sucursales', { method: 'POST', body });
        }
        cancelarEdicionSucursal();
        await cargarSucursalesAdmin();
        await cargarSucursales();
    } catch (err) {
        alert(err.message || 'No se pudo guardar la sucursal.');
    }
}

/* ---------- Usuarios ---------- */

// Muestra u oculta el selector de sucursal en el formulario de Usuarios según el tipo elegido (admin no necesita sucursal).
function actualizarVisibilidadSucursalUsuario() {
    const esEmpleado = document.getElementById('usu-tipo').value === 'empleado';
    document.getElementById('usu-sucursal').classList.toggle('hidden', !esEmpleado);
}

// Trae los usuarios del sistema y los pinta en la lista de administración.
async function cargarUsuariosAdmin() {
    try {
        usuariosCompleto = await apiFetch('/usuarios');
        usuariosPagina = 1;
        renderUsuarios();
    } catch (err) { /* deja la lista como estaba si falla la carga */ }
}

// Dibuja la lista de usuarios con su botón Editar.
function renderUsuarios() {
    const ul = document.getElementById('lista-usuarios');
    ul.innerHTML = '';
    paginar(usuariosCompleto, usuariosPagina).forEach(u => {
        const li = document.createElement('li');
        let texto = u.name + ' (' + u.username + ') — ' + (u.tipo === 'admin' ? 'Administrador' : 'Empleado');
        if (u.sucursal) texto += ' — ' + u.sucursal.nombre;
        const span = document.createElement('span');
        span.textContent = texto;
        li.appendChild(span);

        const grupo = document.createElement('div');
        grupo.className = 'li-acciones';
        grupo.appendChild(crearBotonEditar('Editar ' + u.name, () => editarUsuario(u)));
        li.appendChild(grupo);

        ul.appendChild(li);
    });

    renderPaginacion('usuarios-paginacion', usuariosCompleto.length, usuariosPagina, pagina => {
        usuariosPagina = pagina;
        renderUsuarios();
    });
}

// Precarga el formulario de Usuarios con los datos del usuario elegido (la contraseña se deja vacía a propósito).
function editarUsuario(u) {
    document.getElementById('usu-edit-id').value = u.id;
    document.getElementById('usu-nombre').value = u.name;
    document.getElementById('usu-username').value = u.username;
    document.getElementById('usu-password').value = '';
    document.getElementById('usu-password').placeholder = 'Nueva contraseña (dejar vacío para no cambiar)';
    document.getElementById('usu-tipo').value = u.tipo;
    actualizarVisibilidadSucursalUsuario();
    document.getElementById('usu-sucursal').value = u.sucursal ? String(u.sucursal.id) : '';
    document.getElementById('usu-submit-btn').innerHTML = '<i class="fas fa-save" aria-hidden="true"></i> Guardar Cambios';
    document.getElementById('usu-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Usuarios y sale del modo edición.
function cancelarEdicionUsuario() {
    document.getElementById('form-usuario').reset();
    document.getElementById('usu-edit-id').value = '';
    document.getElementById('usu-password').placeholder = 'Contraseña';
    actualizarVisibilidadSucursalUsuario();
    document.getElementById('usu-submit-btn').innerHTML = '<i class="fas fa-plus" aria-hidden="true"></i> Crear Usuario';
    document.getElementById('usu-cancelar-btn').classList.add('hidden');
}

// Envía el formulario de Usuarios (crea o edita); si la contraseña quedó vacía en una edición, no se manda (no se cambia).
async function guardarUsuario() {
    const nombre = document.getElementById('usu-nombre').value.trim();
    const username = document.getElementById('usu-username').value.trim();
    const password = document.getElementById('usu-password').value;
    const tipo = document.getElementById('usu-tipo').value;
    const sucursalId = document.getElementById('usu-sucursal').value;
    if (!nombre || !username) return;
    if (tipo === 'empleado' && !sucursalId) {
        alert('Selecciona una sucursal para el empleado');
        return;
    }
    const editId = document.getElementById('usu-edit-id').value;
    if (!editId && !password) {
        alert('La contraseña es obligatoria para un usuario nuevo');
        return;
    }

    const body = { name: nombre, username, tipo };
    if (tipo === 'empleado') body.sucursal_id = sucursalId;
    if (password) body.password = password;

    try {
        if (editId) {
            await apiFetch('/usuarios/' + editId, { method: 'PUT', body });
        } else {
            await apiFetch('/usuarios', { method: 'POST', body });
        }
        cancelarEdicionUsuario();
        await cargarUsuariosAdmin();
    } catch (err) {
        alert(err.message || 'No se pudo guardar el usuario.');
    }
}

/* ---------- Cierres Diarios ---------- */

// Arma el query string de sucursal para las llamadas de Cierres Diarios.
function parametrosCierre() {
    const params = [];
    const sucursalId = document.getElementById('cierre-sucursal').value;
    const desde = document.getElementById('cierre-desde').value;
    const hasta = document.getElementById('cierre-hasta').value;
    if (sucursalId) params.push('sucursal_id=' + sucursalId);
    if (desde) params.push('desde=' + desde);
    if (hasta) params.push('hasta=' + hasta);
    return params;
}

// Trae los totales de ventas por día y los pinta en la tabla de Cierres Diarios.
async function cargarCierres() {
    document.getElementById('cierre-detalle').innerHTML = '';
    try {
        const params = parametrosCierre();
        const path = '/cierres' + (params.length ? '?' + params.join('&') : '');
        cierresCompleto = await apiFetch(path);
        cierresPagina = 1;
        renderCierres();
    } catch (err) {
        document.querySelector('#tab-cierres tbody').innerHTML = '';
    }
}

// Dibuja la tabla de Cierres Diarios (un renglón por día, con su total).
function renderCierres() {
    const tbody = document.querySelector('#tab-cierres tbody');
    tbody.innerHTML = '';
    paginar(cierresCompleto, cierresPagina).forEach(d => {
        const tr = document.createElement('tr');
        const tdFecha = document.createElement('td'); tdFecha.textContent = d.dia;
        const tdCant = document.createElement('td'); tdCant.textContent = d.cantidad_ventas;
        const tdTotal = document.createElement('td'); tdTotal.textContent = '$' + d.total;
        const tdDetalle = document.createElement('td'); tdDetalle.className = 'acciones';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'edit-btn';
        btn.textContent = 'Ver';
        btn.setAttribute('aria-label', 'Ver detalle del ' + d.dia);
        btn.addEventListener('click', () => verDetalleDia(d.dia));
        tdDetalle.appendChild(btn);
        tr.append(tdFecha, tdCant, tdTotal, tdDetalle);
        tbody.appendChild(tr);
    });

    renderPaginacion('cierres-paginacion', cierresCompleto.length, cierresPagina, pagina => {
        cierresPagina = pagina;
        renderCierres();
    });
}

// Trae y muestra el detalle de ventas de un día puntual (al hacer click sobre esa fila).
async function verDetalleDia(dia) {
    const cont = document.getElementById('cierre-detalle');
    cont.textContent = 'Cargando...';
    try {
        const sucursalId = document.getElementById('cierre-sucursal').value;
        const path = '/cierres/' + dia + (sucursalId ? '?sucursal_id=' + sucursalId : '');
        renderDetalleDia(dia, await apiFetch(path));
    } catch (err) {
        cont.textContent = 'No se pudo cargar el detalle de ese día.';
    }
}

// Dibuja el detalle de ventas de un día específico.
function renderDetalleDia(dia, ventas) {
    const cont = document.getElementById('cierre-detalle');
    cont.innerHTML = '';

    const titulo = document.createElement('h3');
    titulo.textContent = 'Detalle del ' + dia;
    cont.appendChild(titulo);

    const ul = document.createElement('ul');
    let total = 0;
    ventas.forEach(v => {
        const li = document.createElement('li');
        let texto = v.producto;
        if (v.cantidad) texto += ' (x' + v.cantidad + ')';
        texto += ' - $' + v.valor;
        if (v.sucursal) texto += ' — ' + v.sucursal.nombre;
        if (v.user) texto += ' — registrado por ' + v.user.username;
        const span = document.createElement('span');
        span.textContent = texto;
        li.appendChild(span);
        ul.appendChild(li);
        total += parseFloat(v.valor || 0);
    });
    cont.appendChild(ul);

    const totalP = document.createElement('p');
    totalP.style.fontWeight = 'bold';
    totalP.textContent = 'Total del día: $' + total.toFixed(2);
    cont.appendChild(totalP);
}

// Descarga el CSV de ventas del rango de fechas elegido. Usa fetch manual (no apiFetch) porque la respuesta es un archivo, no JSON.
async function exportarVentasCSV() {
    const desde = document.getElementById('cierre-desde').value;
    const hasta = document.getElementById('cierre-hasta').value;
    if (!desde || !hasta) {
        alert('Elegí un rango de fechas (Desde y Hasta) para exportar.');
        return;
    }
    let path = '/cierres/exportar?desde=' + desde + '&hasta=' + hasta;
    const sucursalId = document.getElementById('cierre-sucursal').value;
    if (sucursalId) path += '&sucursal_id=' + sucursalId;

    try {
        const res = await fetch(API_BASE + path, { headers: { Authorization: 'Bearer ' + getToken() } });
        if (!res.ok) throw new Error('No se pudo generar el archivo.');
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `ventas_${desde}_a_${hasta}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        alert(err.message || 'No se pudo descargar el reporte.');
    }
}

/* ---------- Mantenimiento (Papelera + Backup) ---------- */

// Trae los registros eliminados (inventario/ventas/reparaciones) y los pinta en la Papelera.
async function cargarPapelera() {
    const ul = document.getElementById('lista-papelera');
    try {
        const sucursalId = document.getElementById('papelera-sucursal').value;
        const path = '/papelera' + (sucursalId ? '?sucursal_id=' + sucursalId : '');
        renderPapelera(await apiFetch(path));
    } catch (err) {
        ul.innerHTML = '';
    }
}

const ETIQUETAS_PAPELERA = { inventario: 'Producto', ventas: 'Venta', reparaciones: 'Reparación' };

// Dibuja la lista de la Papelera con su botón Restaurar.
function renderPapelera(items) {
    const ul = document.getElementById('lista-papelera');
    ul.innerHTML = '';

    if (!items.length) {
        const li = document.createElement('li');
        li.textContent = 'La papelera está vacía.';
        ul.appendChild(li);
        return;
    }

    items.forEach(item => {
        const li = document.createElement('li');
        let texto = (ETIQUETAS_PAPELERA[item.tipo] || item.tipo) + ': ' + item.descripcion;
        if (item.sucursal) texto += ' — ' + item.sucursal;
        texto += ' — eliminado el ' + item.eliminado_el.slice(0, 10);
        const span = document.createElement('span');
        span.textContent = texto;
        li.appendChild(span);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'edit-btn';
        btn.innerHTML = '<i class="fas fa-trash-restore" aria-hidden="true"></i> Restaurar';
        btn.addEventListener('click', () => restaurarDePapelera(item.tipo, item.id));
        li.appendChild(btn);

        ul.appendChild(li);
    });
}

// Restaura un registro eliminado de vuelta a su lista normal.
async function restaurarDePapelera(tipo, id) {
    try {
        await apiFetch('/papelera/' + tipo + '/' + id + '/restaurar', { method: 'POST' });
        await cargarPapelera();
    } catch (err) {
        alert(err.message || 'No se pudo restaurar el elemento.');
    }
}

// Descarga el respaldo SQL completo de la base de datos. También usa fetch manual por ser un archivo.
async function descargarBackup() {
    try {
        const res = await fetch(API_BASE + '/backup/exportar', { headers: { Authorization: 'Bearer ' + getToken() } });
        if (!res.ok) throw new Error('No se pudo generar el backup.');
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        const fecha = new Date().toISOString().slice(0, 10);
        a.download = `backup_relojeria_jimmy_${fecha}.sql`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        alert(err.message || 'No se pudo descargar el backup.');
    }
}

/* ---------- Solicitudes de recuperación de contraseña ---------- */

// Trae las solicitudes pendientes de "olvidé mi contraseña" para la pantalla de Mantenimiento.
async function cargarSolicitudesPassword() {
    const ul = document.getElementById('lista-solicitudes-password');
    try {
        renderSolicitudesPassword(await apiFetch('/solicitudes-password'));
    } catch (err) {
        ul.innerHTML = '';
    }
}

// Dibuja la lista de solicitudes de recuperación de contraseña con su botón "Marcar atendida".
function renderSolicitudesPassword(solicitudes) {
    const ul = document.getElementById('lista-solicitudes-password');
    ul.innerHTML = '';

    if (!solicitudes.length) {
        const li = document.createElement('li');
        li.textContent = 'No hay solicitudes pendientes.';
        ul.appendChild(li);
        return;
    }

    solicitudes.forEach(s => {
        const li = document.createElement('li');
        const span = document.createElement('span');
        span.textContent = (s.user ? s.user.name + ' (' + s.user.username + ')' : 'Usuario eliminado')
            + ' — pedida el ' + s.created_at.slice(0, 10);
        li.appendChild(span);

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'edit-btn';
        btn.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Marcar atendida';
        btn.addEventListener('click', () => atenderSolicitudPassword(s.id));
        li.appendChild(btn);

        ul.appendChild(li);
    });
}

// Marca una solicitud de recuperación de contraseña como resuelta.
async function atenderSolicitudPassword(id) {
    try {
        await apiFetch('/solicitudes-password/' + id + '/atender', { method: 'POST' });
        await cargarSolicitudesPassword();
    } catch (err) {
        alert(err.message || 'No se pudo marcar la solicitud como atendida.');
    }
}

cargarSucursales();
restaurarSesion();
