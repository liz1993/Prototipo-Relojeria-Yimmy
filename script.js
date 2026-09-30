const API_BASE = 'http://127.0.0.1:8001/api';
const UMBRAL_STOCK_BAJO = 5;
const ESTADOS_REPARACION = {
    pendiente: 'Pendiente',
    en_proceso: 'En proceso',
    listo: 'Listo para entregar',
    entregado: 'Entregado',
};

let sesionActual = null;
// Rubro activo (Relojería/Joyería): se guarda en localStorage para que sobreviva
// a un F5 -- todas las pantallas (Ventas, Inventario, Reparaciones, etc.) filtran
// sus datos según este valor, nunca se mezclan entre sí.
let moduloActivo = localStorage.getItem('rj_modulo') || 'relojeria';
let reparacionEditando = null;
let sucursalesCache = [];

const TAMANO_PAGINA = 10;
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
let movimientosCompleto = [];
let movimientosPagina = 1;

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

// Actualiza el tooltip (title, visible al pasar el mouse) y el aria-label (lectores de
// pantalla) de un botón de ícono -- se usa en los submit "Guardar" de los formularios,
// que siempre muestran el mismo emoji pero cambian de significado entre "crear" y
// "guardar cambios" según si hay un registro en edición.
function actualizarTooltipBoton(id, texto) {
    const btn = document.getElementById(id);
    btn.title = texto;
    btn.setAttribute('aria-label', texto);
}

// Abre como modal el formulario de "crear" (y muestra su botón de guardar,
// que vive en la cabecera vía el atributo form="...") -- se reutiliza en
// Ventas/Inventario/Reparaciones/Movimientos/Sucursales/Usuarios en vez de
// repetir la misma lógica seis veces. El overlay (formId + "-overlay") es
// el que realmente se oculta/muestra; el <form> es la "caja" blanca de adentro.
function mostrarFormulario(formId, submitBtnId) {
    document.getElementById(formId + '-overlay').classList.remove('hidden');
    const submitBtn = document.getElementById(submitBtnId);
    if (submitBtn) submitBtn.classList.remove('hidden');
    document.body.style.overflow = 'hidden';
    // Primer campo real (no los <input type="hidden">) recibe el foco, como en cualquier modal.
    const primerCampo = document.querySelector('#' + formId + ' input:not([type="hidden"]), #' + formId + ' select');
    if (primerCampo) primerCampo.focus();
}

// Cierra el modal y esconde el botón de guardar de la cabecera -- sin esto,
// ese botón (ligado al form por fuera) seguiría siendo clickeable con el
// formulario invisible.
function ocultarFormulario(formId, submitBtnId) {
    document.getElementById(formId + '-overlay').classList.add('hidden');
    const submitBtn = document.getElementById(submitBtnId);
    if (submitBtn) submitBtn.classList.add('hidden');
    document.body.style.overflow = '';
}

// Botón "+ Nuevo/a": si el modal está cerrado lo abre, si ya está abierto lo cierra.
function alternarFormulario(formId, submitBtnId) {
    const abierto = !document.getElementById(formId + '-overlay').classList.contains('hidden');
    if (abierto) ocultarFormulario(formId, submitBtnId);
    else mostrarFormulario(formId, submitBtnId);
}

// Cierra el modal si se clickeó el fondo oscurecido (no el formulario en sí) -- patrón estándar de modal.
function cerrarModalSiFondo(event, formId, submitBtnId) {
    if (event.target.id === formId + '-overlay') ocultarFormulario(formId, submitBtnId);
}

// Cierra cualquier modal abierto con la tecla Escape.
document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    cerrarLightboxFoto();
    document.querySelectorAll('.modal-overlay:not(.hidden):not(#lightbox-foto-overlay)').forEach(overlay => {
        const formId = overlay.id.replace(/-overlay$/, '');
        const submitBtn = overlay.querySelector('form button[type="submit"]') || document.querySelector(`button[form="${formId}"]`);
        ocultarFormulario(formId, submitBtn ? submitBtn.id : '');
    });
});

// Lightbox de fotos: agranda la miniatura clickeada (inventario/reparaciones) sobre fondo oscurecido.
function abrirLightboxFoto(url, alt) {
    const img = document.getElementById('lightbox-foto-img');
    img.src = url;
    img.alt = alt;
    document.getElementById('lightbox-foto-overlay').classList.remove('hidden');
}

function cerrarLightboxFoto() {
    document.getElementById('lightbox-foto-overlay').classList.add('hidden');
    document.getElementById('lightbox-foto-img').src = '';
}

// Cierra el lightbox solo si se clickeó el fondo oscurecido, no la imagen en sí.
function cerrarLightboxSiFondo(event) {
    if (event.target.id === 'lightbox-foto-overlay') cerrarLightboxFoto();
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
    btnSig.className = 'back-btn';
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

    ['reportes-sucursal', 'cierre-sucursal', 'papelera-sucursal', 'act-sucursal', 'filtro-v-sucursal'].forEach(id => {
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
// Pantallas de "antes de entrar" (sin sesión): llevan el fondo oscuro tipo vitrina de joyería.
const PANTALLAS_SIN_SESION = ['sec-login', 'sec-registro', 'sec-recuperar'];

function navigateTo(id) {
    document.querySelectorAll('.screen').forEach(el => el.classList.add('hidden'));
    document.getElementById(id).classList.remove('hidden');
    // Si había un modal de formulario abierto en la pantalla que se deja, se
    // esconde solo (la sección ya lo tapa) pero el scroll bloqueado del body
    // y el botón de guardar de la cabecera quedarían pegados si no se
    // resetean acá.
    document.querySelectorAll('.modal-overlay:not(.hidden)').forEach(overlay => {
        overlay.classList.add('hidden');
        const formId = overlay.id.replace(/-overlay$/, '');
        const submitBtn = document.querySelector(`button[form="${formId}"]`);
        if (submitBtn) submitBtn.classList.add('hidden');
    });
    document.body.style.overflow = '';
    const sinSesion = PANTALLAS_SIN_SESION.includes(id);
    document.body.classList.toggle('fondo-login', sinSesion);
    // El toggle de modo oscuro/claro es para adentro de la app (admin o
    // empleado, cualquiera de los dos) -- en login/registro/recuperar no
    // aplica, ya tienen su propio fondo oscuro fijo de marca.
    document.getElementById('tema-btn').classList.toggle('hidden', sinSesion);

    if (id === 'sec-inventario') cargarInventario();
    if (id === 'sec-ventas') { cargarVentas(); cargarInventarioParaVentas(); cargarEmpleadosParaFiltroVentas(); cargarCierreHoy(); }
    if (id === 'sec-reparaciones') cargarReparaciones();
    if (id === 'sec-reportes') cargarReportes();
    if (id === 'sec-sucursales') cargarSucursalesAdmin();
    if (id === 'sec-usuarios') cargarUsuariosAdmin();
    if (id === 'sec-cierres') cargarCierres();
    if (id === 'sec-mantenimiento') { cargarPapelera(); cargarSolicitudesPassword(); cargarModulosSucursal(); }
    if (id === 'sec-movimientos') { cargarInventarioParaMovimientos(); cargarMovimientos(); }
    if (id === 'sec-actividad') cargarActividad();
}

// Muestra/oculta en el DOM los botones y controles exclusivos de admin, según esAdmin().
function aplicarPermisos() {
    document.getElementById('reportes-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('sucursales-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('usuarios-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('cierres-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('mantenimiento-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('actividad-btn').classList.toggle('hidden', !esAdmin());
    document.getElementById('inventario-admin-controls').classList.toggle('hidden', !esAdmin());
    document.getElementById('th-inv-costo').classList.toggle('hidden', !esAdmin());
    document.getElementById('th-mov-acciones').classList.toggle('hidden', !esAdmin());
    document.getElementById('v-sucursal-activa').classList.toggle('hidden', !esAdmin());
    document.getElementById('r-sucursal-activa').classList.toggle('hidden', !esAdmin());
    document.getElementById('filtro-v-sucursal').classList.toggle('hidden', !esAdmin());
    document.getElementById('filtro-v-empleado').classList.toggle('hidden', !esAdmin());
    document.getElementById('ventas-admin-controls').classList.toggle('hidden', !esAdmin());
}

/* ---------- Modo oscuro / claro (para adentro de la app, admin o empleado) ---------- */

// Aplica el tema guardado (si había uno) al cargar la página, antes de mostrar nada.
function aplicarTemaGuardado() {
    if (localStorage.getItem('rj_tema') === 'oscuro') {
        document.body.classList.add('tema-oscuro');
        actualizarBotonTema();
    }
}

// Cambia entre modo oscuro y claro, lo persiste, y actualiza el ícono/tooltip del botón.
function alternarTema() {
    document.body.classList.toggle('tema-oscuro');
    localStorage.setItem('rj_tema', document.body.classList.contains('tema-oscuro') ? 'oscuro' : 'claro');
    actualizarBotonTema();
}

function actualizarBotonTema() {
    const oscuro = document.body.classList.contains('tema-oscuro');
    const btn = document.getElementById('tema-btn');
    btn.innerHTML = oscuro
        ? '<i class="fas fa-sun" aria-hidden="true"></i>'
        : '<i class="fas fa-moon" aria-hidden="true"></i>';
    btn.title = oscuro ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro';
    btn.setAttribute('aria-label', btn.title);
}

/* ---------- Selector de módulo (Relojería / Joyería) ---------- */

// El admin siempre puede ver los dos rubros; un empleado solo si SU sucursal
// tiene Joyería habilitada (dato que llega en sesionActual.sucursal, ver AuthController::formatUser).
function joyeriaPermitida() {
    if (esAdmin()) return true;
    return !!(sesionActual && sesionActual.sucursal && sesionActual.sucursal.joyeria_habilitada);
}

// Query string reutilizable para cualquier fetch que dependa del rubro activo.
function moduloQS() {
    return 'modulo=' + moduloActivo;
}

// Habilita/deshabilita la pestaña "Joyería" según joyeriaPermitida(), y si el
// rubro activo quedó en "joyeria" sin permiso (ej. un admin lo apagó mientras el
// empleado lo tenía elegido), lo vuelve a "relojeria" automáticamente.
function aplicarPermisosModulo() {
    const permitida = joyeriaPermitida();
    const btnJoyeria = document.getElementById('modulo-btn-joyeria');
    btnJoyeria.disabled = !permitida;
    document.getElementById('modulo-aviso').classList.toggle('hidden', permitida);

    if (!permitida && moduloActivo === 'joyeria') {
        moduloActivo = 'relojeria';
        localStorage.setItem('rj_modulo', moduloActivo);
    }
    document.getElementById('modulo-btn-relojeria').classList.toggle('active', moduloActivo === 'relojeria');
    document.getElementById('modulo-btn-joyeria').classList.toggle('active', moduloActivo === 'joyeria');
}

// Cambia el rubro activo, lo persiste, y recarga los datos de la pantalla en la
// que se esté parado (además del resumen del dashboard) para que se vean acordes.
function cambiarModulo(modulo) {
    if (modulo === 'joyeria' && !joyeriaPermitida()) return;
    moduloActivo = modulo;
    localStorage.setItem('rj_modulo', modulo);
    aplicarPermisosModulo();
    cargarAlertas();
    cargarResumenAdmin();
    const pantallaActual = document.querySelector('.screen:not(.hidden)');
    if (pantallaActual) navigateTo(pantallaActual.id);
}

// Se llama justo después de iniciar sesión (o restaurarla): guarda el usuario actual, aplica permisos y navega al dashboard.
function mostrarDashboard(user) {
    document.getElementById('cargando-sesion').classList.add('hidden');
    sesionActual = user;
    let bienvenida = "Bienvenido/a, " + user.username;
    if (user.sucursal) bienvenida += ' — ' + user.sucursal.nombre;
    document.getElementById('msg-bienvenida').innerText = bienvenida;
    document.getElementById('contenedor-app').classList.add('ancho');
    aplicarPermisos();
    aplicarPermisosModulo();
    navigateTo('sec-dashboard');
    cargarAlertas();
    cargarResumenAdmin();
}

/* ---------- Resumen del dashboard: gráfico de ventas (todas las sucursales para admin, la propia para un empleado) ---------- */

// Estado del filtro del gráfico: qué sucursal (null = todas, o la única que puede ver un empleado) y si agrupa por día o por mes.
let resumenSucursalId = null;
let resumenPeriodo = 'dias';

// Muestra el resumen del dashboard para cualquier rol. Las chips para elegir
// sucursal solo tienen sentido para admin (que ve todas); un empleado siempre
// ve el gráfico de su propia sucursal (el backend ya la fuerza en /cierres,
// ver CierreDiarioController::aplicarFiltros), así que mostrarle chips de
// otras sedes sería engañoso -- clickearlas no cambiaría nada igual.
async function cargarResumenAdmin() {
    const cont = document.getElementById('resumen-admin');
    cont.classList.remove('hidden');
    document.getElementById('resumen-sucursales-card').classList.toggle('hidden', !esAdmin());
    if (esAdmin()) {
        renderChipsSucursales();
    } else {
        resumenSucursalId = null;
    }
    await cargarChartVentasDia();
}

// Dibuja las chips de sucursal (los "puntos" del negocio) + una chip "Todas" -- son clickeables,
// y la que está activa filtra el gráfico de más abajo.
function renderChipsSucursales() {
    const cont = document.getElementById('resumen-sucursales-lista');
    cont.innerHTML = '';

    const chipTodas = document.createElement('span');
    chipTodas.className = 'chip-sucursal' + (resumenSucursalId === null ? ' active' : '');
    chipTodas.setAttribute('role', 'button');
    chipTodas.setAttribute('tabindex', '0');
    const iconoTodas = document.createElement('i');
    iconoTodas.className = 'fas fa-layer-group';
    iconoTodas.setAttribute('aria-hidden', 'true');
    chipTodas.appendChild(iconoTodas);
    chipTodas.appendChild(document.createTextNode(' Todas'));
    const activarTodas = () => seleccionarSucursalChart(null);
    chipTodas.addEventListener('click', activarTodas);
    chipTodas.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activarTodas(); } });
    cont.appendChild(chipTodas);

    if (!sucursalesCache.length) return;

    sucursalesCache.forEach(s => {
        const chip = document.createElement('span');
        chip.className = 'chip-sucursal' + (resumenSucursalId === s.id ? ' active' : '');
        chip.setAttribute('role', 'button');
        chip.setAttribute('tabindex', '0');
        const icono = document.createElement('i');
        icono.className = 'fas fa-store';
        icono.setAttribute('aria-hidden', 'true');
        chip.appendChild(icono);
        chip.appendChild(document.createTextNode(' ' + s.nombre));
        const activar = () => seleccionarSucursalChart(s.id);
        chip.addEventListener('click', activar);
        chip.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); activar(); } });
        cont.appendChild(chip);
    });
}

// Cambia qué sucursal filtra el gráfico (o null para "Todas") y lo vuelve a cargar.
function seleccionarSucursalChart(sucursalId) {
    resumenSucursalId = sucursalId;
    renderChipsSucursales();
    cargarChartVentasDia();
}

// Cambia si el gráfico agrupa por día o por mes (botones "Por día" / "Por mes") y lo vuelve a cargar.
function cambiarPeriodoChart(periodo) {
    resumenPeriodo = periodo;
    document.getElementById('chart-periodo-dias').classList.toggle('active', periodo === 'dias');
    document.getElementById('chart-periodo-mes').classList.toggle('active', periodo === 'mes');
    const unidad = periodo === 'dias' ? 'día' : 'mes';
    document.getElementById('chart-explicacion-unidad').textContent = unidad;
    document.getElementById('chart-explicacion-unidad2').textContent = unidad;
    cargarChartVentasDia();
}

// Trae los cierres diarios (ya existentes en /cierres, filtrables por sucursal_id) y arma los
// puntos del gráfico según el período activo: últimos 14 días parejos (con $0 en los días sin
// ventas), o los últimos 6 meses sumados. Actualiza también el título del gráfico.
async function cargarChartVentasDia() {
    const cont = document.getElementById('chart-ventas-dia');
    const titulo = document.getElementById('chart-titulo');
    // Un empleado no elige sucursal por chip (el backend siempre le fuerza la
    // suya), así que el título usa directo la de su sesión en vez de buscarla
    // en sucursalesCache por resumenSucursalId (que para él queda en null).
    let nombreSucursal;
    if (esAdmin()) {
        const sucursal = sucursalesCache.find(s => s.id === resumenSucursalId);
        nombreSucursal = sucursal ? sucursal.nombre : 'Todas las sucursales';
    } else {
        nombreSucursal = sesionActual.sucursal ? sesionActual.sucursal.nombre : '';
    }
    titulo.innerHTML = '<i class="fas fa-chart-column" aria-hidden="true"></i> ';
    titulo.appendChild(document.createTextNode('Ventas ' + (resumenPeriodo === 'dias' ? 'por día' : 'por mes') + ' — ' + nombreSucursal));

    try {
        let path = '/cierres?' + moduloQS();
        if (resumenSucursalId) path += '&sucursal_id=' + resumenSucursalId;
        const cierres = await apiFetch(path);
        // "dia" puede venir como "YYYY-MM-DD" (MySQL) o "YYYY-MM-DD HH:MM:SS" (SQLite,
        // que no tiene tipo DATE nativo) -- se recorta a los primeros 10 caracteres.
        const porDia = {};
        cierres.forEach(c => { porDia[c.dia.slice(0, 10)] = parseFloat(c.total || 0); });

        const puntos = resumenPeriodo === 'dias' ? construirPuntosPorDia(porDia) : construirPuntosPorMes(porDia);
        renderChartVentasDia(cont, puntos, resumenPeriodo);
    } catch (err) {
        cont.innerHTML = '<p class="chart-empty">No se pudo cargar el gráfico de ventas.</p>';
    }
}

// Últimos 14 días calendario, completando con $0 los que no tienen ventas.
function construirPuntosPorDia(porDia) {
    const puntos = [];
    for (let i = 13; i >= 0; i--) {
        const d = new Date();
        d.setDate(d.getDate() - i);
        const iso = d.toISOString().slice(0, 10);
        const fecha = new Date(iso + 'T00:00:00');
        puntos.push({
            total: porDia[iso] || 0,
            etiquetaEje: fecha.toLocaleDateString('es-CO', { day: '2-digit', month: '2-digit' }),
            etiquetaTooltip: fecha.toLocaleDateString('es-CO', { day: 'numeric', month: 'long' }),
        });
    }
    return puntos;
}

// Últimos 6 meses calendario, sumando todos los días de cada mes (incluye meses en $0 si no hubo ventas).
function construirPuntosPorMes(porDia) {
    const totalPorMes = {};
    Object.keys(porDia).forEach(iso => {
        const clave = iso.slice(0, 7); // "YYYY-MM"
        totalPorMes[clave] = (totalPorMes[clave] || 0) + porDia[iso];
    });

    const puntos = [];
    for (let i = 5; i >= 0; i--) {
        const d = new Date();
        d.setMonth(d.getMonth() - i, 1);
        const clave = d.toISOString().slice(0, 7);
        puntos.push({
            total: totalPorMes[clave] || 0,
            etiquetaEje: d.toLocaleDateString('es-CO', { month: 'short' }).replace('.', ''),
            etiquetaTooltip: d.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' }),
        });
    }
    return puntos;
}

// Redondea hacia arriba a un número "prolijo" (1/2/5 x potencia de 10) para el techo del eje Y.
function techoAgradableChart(valor) {
    if (valor <= 0) return 10;
    const magnitud = Math.pow(10, Math.floor(Math.log10(valor)));
    const normal = valor / magnitud;
    let techo = 10;
    if (normal <= 1) techo = 1;
    else if (normal <= 2) techo = 2;
    else if (normal <= 5) techo = 5;
    return techo * magnitud;
}

// Arma el SVG del gráfico de barras a mano (sin librerías): barras con tope redondeado
// ancladas a la base, grilla horizontal recesiva, etiqueta directa solo en la barra más
// alta, y tooltip + foco de teclado en cada barra individual. "puntos" ya viene con las
// etiquetas formateadas (día u mes, según construirPuntosPorDia/Mes).
function renderChartVentasDia(cont, puntos, periodo) {
    const totalVentas = puntos.reduce((acc, p) => acc + p.total, 0);
    if (totalVentas === 0) {
        const rango = periodo === 'dias' ? 'los últimos 14 días' : 'los últimos 6 meses';
        cont.innerHTML = `<p class="chart-empty">Todavía no hay ventas registradas en ${rango}.</p>`;
        return;
    }

    const W = 700, H = 300;
    // marginBottom más grande cuando hay 14 fechas rotadas (necesitan más aire abajo
    // que las 6 etiquetas de mes, que van derechas).
    const marginLeft = 55, marginRight = 10, marginTop = 30, marginBottom = puntos.length > 8 ? 55 : 35;
    const plotW = W - marginLeft - marginRight;
    const plotH = H - marginTop - marginBottom;

    const yMax = techoAgradableChart(Math.max(...puntos.map(p => p.total)));
    const n = puntos.length;
    const slot = plotW / n;
    const barW = Math.min(24, slot * 0.55);
    const indiceMax = puntos.reduce((iMax, p, i, arr) => p.total > arr[iMax].total ? i : iMax, 0);
    // Nunca se saltan etiquetas -- con 14 barras (por día) se rotan para que las 14
    // fechas completas entren sin pisarse; con 6 (por mes) van derechas, sobra espacio.
    const rotarEtiquetas = n > 8;

    const escalaY = valor => plotH - (valor / yMax) * plotH;

    let svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Gráfico de barras: ventas totales ${periodo === 'dias' ? 'por día' : 'por mes'}">`;

    // Grilla horizontal (0%, 50%, 100% del techo) con su etiqueta de eje Y.
    [0, 0.5, 1].forEach(frac => {
        const y = marginTop + plotH - frac * plotH;
        svg += `<line class="chart-gridline" x1="${marginLeft}" y1="${y}" x2="${W - marginRight}" y2="${y}"></line>`;
        svg += `<text class="chart-axis-text" x="${marginLeft - 8}" y="${y + 4}" text-anchor="end">$${Math.round(yMax * frac)}</text>`;
    });

    // Barras + etiquetas del eje X + un grupo de "destellitos" por barra (invisibles
    // hasta que se les pasa el mouse o el foco, ver CSS .chart-bar-group:hover).
    puntos.forEach((p, i) => {
        const cx = marginLeft + slot * i + slot / 2;
        const x = cx - barW / 2;
        const yTop = marginTop + escalaY(p.total);
        const yBase = marginTop + plotH;
        const r = Math.min(4, barW / 2);
        const alturaBarra = Math.max(0, yBase - yTop);

        // Tope redondeado, base cuadrada -- un rect con rx redondearía las 4 esquinas,
        // así que se arma como path para que solo el tope quede redondeado.
        const path = alturaBarra < r
            ? `M${x},${yBase} L${x},${yTop} L${x + barW},${yTop} L${x + barW},${yBase} Z`
            : `M${x},${yBase} L${x},${yTop + r} Q${x},${yTop} ${x + r},${yTop} L${x + barW - r},${yTop} Q${x + barW},${yTop} ${x + barW},${yTop + r} L${x + barW},${yBase} Z`;

        const etiquetaAria = p.etiquetaTooltip + ': $' + Math.round(p.total);

        svg += `<g class="chart-bar-group" tabindex="0" role="button" aria-label="${etiquetaAria}" data-tooltip="${p.etiquetaTooltip}" data-total="${p.total}" data-cx="${cx}" data-ytop="${yTop}">`;
        svg += `<path class="chart-bar" d="${path}"></path>`;
        svg += `<g class="chart-sparkles">`;
        svg += estrellaPath(cx - barW * 0.2, yTop + 6, 3.2, 'sparkle-1');
        svg += estrellaPath(cx + barW * 0.25, yTop - 4, 2.4, 'sparkle-2');
        svg += estrellaPath(cx, yTop + 14, 2, 'sparkle-3');
        svg += `</g></g>`;

        // Todas las fechas, siempre completas -- rotadas cuando son 14 (por día) para
        // que entren sin pisarse; derechas cuando son 6 (por mes).
        if (rotarEtiquetas) {
            svg += `<text class="chart-axis-text" x="${cx}" y="${yBase + 14}" text-anchor="end" transform="rotate(-40 ${cx} ${yBase + 14})">${p.etiquetaEje}</text>`;
        } else {
            svg += `<text class="chart-axis-text" x="${cx}" y="${yBase + 18}" text-anchor="middle">${p.etiquetaEje}</text>`;
        }

        // Etiqueta directa solo en la barra más alta (la que cuenta la historia), el resto vive en el tooltip.
        if (i === indiceMax) {
            svg += `<text class="chart-value-label" x="${cx}" y="${yTop - 8}" text-anchor="middle">$${Math.round(p.total)}</text>`;
        }
    });

    svg += `</svg><div class="chart-tooltip" id="chart-tooltip-ventas"></div>`;
    cont.innerHTML = svg;

    const svgEl = cont.querySelector('svg');
    const tooltip = cont.querySelector('#chart-tooltip-ventas');

    const mostrarTooltip = bar => {
        const escala = svgEl.getBoundingClientRect().width / W;
        const cx = parseFloat(bar.dataset.cx);
        const yTop = parseFloat(bar.dataset.ytop);
        tooltip.innerHTML = '';
        const linea1 = document.createElement('div');
        linea1.appendChild(document.createTextNode(bar.dataset.tooltip));
        const linea2 = document.createElement('strong');
        linea2.appendChild(document.createTextNode('$' + Math.round(parseFloat(bar.dataset.total))));
        tooltip.append(linea1, linea2);
        tooltip.style.left = (cx * escala) + 'px';
        tooltip.style.top = (yTop * escala - 8) + 'px';
        tooltip.classList.add('visible');
    };
    const ocultarTooltip = () => tooltip.classList.remove('visible');

    cont.querySelectorAll('.chart-bar-group').forEach(grupo => {
        grupo.addEventListener('pointerenter', () => mostrarTooltip(grupo));
        grupo.addEventListener('pointerleave', ocultarTooltip);
        grupo.addEventListener('focus', () => mostrarTooltip(grupo));
        grupo.addEventListener('blur', ocultarTooltip);
    });
}

// Arma un pathData de estrellita de 4 puntas (el "destellito"), centrada en (cx,cy) con radio r.
function estrellaPath(cx, cy, r, claseExtra) {
    const rInterno = r * 0.35;
    const puntos4 = [
        [cx, cy - r], [cx + rInterno, cy - rInterno], [cx + r, cy], [cx + rInterno, cy + rInterno],
        [cx, cy + r], [cx - rInterno, cy + rInterno], [cx - r, cy], [cx - rInterno, cy - rInterno],
    ];
    const d = 'M' + puntos4.map(p => p.join(',')).join(' L') + ' Z';
    return `<path class="chart-sparkle ${claseExtra}" d="${d}"></path>`;
}

// Arma el texto de alertas del dashboard (stock bajo, reparaciones pendientes y, si es admin, solicitudes de contraseña).
async function cargarAlertas() {
    const el = document.getElementById('alertas-texto');
    const caja = document.getElementById('alertas-caja');
    const icono = document.getElementById('alertas-icono');
    try {
        const pedidos = [apiFetch('/inventario?' + moduloQS()), apiFetch('/reparaciones?' + moduloQS())];
        if (esAdmin()) pedidos.push(apiFetch('/solicitudes-password'));
        const [inventario, reparaciones, solicitudes] = await Promise.all(pedidos);

        const stockBajo = inventario.filter(i => i.cantidad > 0 && i.cantidad <= UMBRAL_STOCK_BAJO).length;
        const stockCero = inventario.filter(i => i.cantidad === 0).length;
        const pendientes = reparaciones.filter(r => r.estado !== 'entregado');
        const haceMasDeUnaSemana = f => f && (Date.now() - new Date(f).getTime()) > 7 * 24 * 60 * 60 * 1000;
        const atrasadas = pendientes.filter(r => haceMasDeUnaSemana(r.fecha)).length;

        // Ventas guardadas offline en este dispositivo: las que ya tienen un
        // error de sincronización son urgentes (necesitan revisión a mano);
        // las que solo están esperando conexión son un aviso normal.
        const ventasPendientes = obtenerVentasPendientes();
        const ventasPendientesError = ventasPendientes.filter(p => p.error).length;
        const ventasPendientesOk = ventasPendientes.length - ventasPendientesError;

        const partes = [];
        if (pendientes.length) partes.push(pendientes.length + ' reparación(es) pendiente(s) de entrega' + (atrasadas ? ' (' + atrasadas + ' con más de una semana)' : ''));
        if (stockBajo) partes.push(stockBajo + ' producto(s) con inventario bajo');
        if (stockCero) partes.push(stockCero + ' producto(s) agotado(s) (sin stock)');
        if (solicitudes && solicitudes.length) partes.push(solicitudes.length + ' solicitud(es) de recuperación de contraseña');
        if (ventasPendientesOk) partes.push(ventasPendientesOk + ' venta(s) guardada(s) sin conexión, esperando para sincronizar');
        if (ventasPendientesError) partes.push(ventasPendientesError + ' venta(s) sin conexión con error al sincronizar (revisar)');
        el.textContent = partes.length ? 'Alertas: ' + partes.join('. ') + '.' : 'Sin alertas pendientes.';

        // Semáforo: rojo si hay algo urgente (stock en cero, una reparación con más
        // de una semana sin entregar, o una venta offline que no pudo sincronizar
        // sola), amarillo si hay algo para revisar pero no urgente, verde si no hay nada.
        caja.classList.remove('alert-ok', 'alert-warn', 'alert-danger');
        if (stockCero > 0 || atrasadas > 0 || ventasPendientesError > 0) {
            caja.classList.add('alert-danger');
            icono.className = 'fas fa-circle-exclamation';
        } else if (partes.length) {
            caja.classList.add('alert-warn');
            icono.className = 'fas fa-bell';
        } else {
            caja.classList.add('alert-ok');
            icono.className = 'fas fa-circle-check';
        }
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
            document.getElementById('contenedor-app').classList.remove('ancho');
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
        alert('¡Usuario ' + nuevoUser + ' registrado con éxito! Un administrador debe aprobar tu cuenta antes de que puedas iniciar sesión.');
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
        // Solo se guarda el correo (nunca la contraseña -- guardarla en el
        // navegador sería inseguro). Si se desmarca, se olvida el que hubiera quedado.
        if (document.getElementById('login-recordarme').checked) {
            localStorage.setItem('rj_email_recordado', eInput);
        } else {
            localStorage.removeItem('rj_email_recordado');
        }
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
    document.getElementById('contenedor-app').classList.remove('ancho');
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
        const reps = await apiFetch('/reparaciones?' + moduloQS() + '&buscar=' + encodeURIComponent(q));
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
            img.onclick = () => abrirLightboxFoto(r.foto_url, img.alt);
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
        let path = '/inventario?' + moduloQS();
        if (esAdmin()) {
            const sucursalId = document.getElementById('inv-sucursal-activa').value;
            if (sucursalId) path += '&sucursal_id=' + sucursalId;
        }
        inventarioCompleto = await apiFetch(path);
        inventarioPagina = 1;
        renderInventario();
    } catch (err) { /* deja la tabla como estaba si falla la carga */ }
}

// Descarga en CSV el inventario visible (misma sucursal/módulo que la tabla en pantalla).
async function exportarInventario() {
    try {
        let path = '/inventario/exportar?' + moduloQS();
        const sucursalId = document.getElementById('inv-sucursal-activa').value;
        if (sucursalId) path += '&sucursal_id=' + sucursalId;
        const res = await fetch(API_BASE + path, { headers: { Authorization: 'Bearer ' + getToken() } });
        if (!res.ok) throw new Error('No se pudo exportar el inventario.');
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `inventario_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        alert(err.message || 'No se pudo exportar el inventario.');
    }
}

// Descarga un CSV de ejemplo con las columnas que espera importarInventario(),
// para que quede claro cómo llenarlo. No pega contra la API: se arma en el navegador.
function descargarPlantillaInventario() {
    const encabezado = ['Código', 'Descripción', 'Cantidad', 'Precio', 'Costo'];
    const ejemplo = ['REL-C1', 'Reloj Casio Clásico', '10', '45.00', '25.00'];
    const filas = [encabezado, ejemplo].map(f => f.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','));
    // BOM al inicio para que Excel detecte UTF-8 y muestre bien los acentos.
    const blob = new Blob(['﻿' + filas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'plantilla_inventario.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

// Importa productos desde un CSV (columnas Código, Descripción, Cantidad, Precio, Costo)
// a la sucursal elegida en el selector de arriba: crea o actualiza por código.
async function importarInventario(input) {
    const archivo = input.files[0];
    if (!archivo) return;

    const sucursalId = document.getElementById('inv-sucursal-activa').value;
    if (!sucursalId) {
        alert('Elegí una sucursal en el selector de arriba antes de importar.');
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('archivo', archivo);
    formData.append('sucursal_id', sucursalId);
    formData.append('modulo', moduloActivo);

    try {
        const resultado = await apiFetch('/inventario/importar', { method: 'POST', body: formData });
        let mensaje = `Importación completa: ${resultado.creados} creado(s), ${resultado.actualizados} actualizado(s).`;
        if (resultado.errores.length) mensaje += '\n\n' + resultado.errores.join('\n');
        alert(mensaje);
        await cargarInventario();
    } catch (err) {
        alert(err.message || 'No se pudo importar el archivo.');
    } finally {
        input.value = '';
    }
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
            img.onclick = () => abrirLightboxFoto(item.foto_url, img.alt);
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
    mostrarFormulario('form-inventario', 'inv-submit-btn');
    document.getElementById('inv-edit-id').value = item.id;
    // Se fija la sucursal real del producto en un campo aparte -- si no, cambiar
    // el filtro de sucursal mientras se edita reasignaría el producto a la
    // sucursal del filtro en vez de dejarlo en la suya (ver agregarProducto()).
    document.getElementById('inv-edit-sucursal-id').value = item.sucursal_id || '';
    document.getElementById('inv-codigo').value = item.codigo;
    document.getElementById('inv-desc').value = item.descripcion;
    document.getElementById('inv-cant').value = item.cantidad;
    document.getElementById('inv-precio').value = item.precio;
    document.getElementById('inv-costo').value = item.costo ?? '';
    document.getElementById('inv-foto').value = '';
    actualizarTooltipBoton('inv-submit-btn', 'Guardar cambios');
    document.getElementById('inv-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Inventario y lo vuelve al modo "Agregar producto" (sale del modo edición).
function cancelarEdicionProducto() {
    document.getElementById('form-inventario').reset();
    document.getElementById('inv-edit-id').value = '';
    document.getElementById('inv-edit-sucursal-id').value = '';
    actualizarTooltipBoton('inv-submit-btn', 'Agregar producto');
    document.getElementById('inv-cancelar-btn').classList.add('hidden');
    ocultarFormulario('form-inventario', 'inv-submit-btn');
}

// Envía el formulario de Inventario (crea o edita según haya un id en edición), como multipart/form-data por la foto.
async function agregarProducto() {
    const descripcion = document.getElementById('inv-desc').value.trim();
    const cantidad = document.getElementById('inv-cant').value;
    const precio = document.getElementById('inv-precio').value;
    const costo = document.getElementById('inv-costo').value;
    if (!descripcion) {
        alert('Completa la descripción');
        return;
    }

    const editId = document.getElementById('inv-edit-id').value;
    // En edición se usa la sucursal real del producto (fijada al entrar en modo
    // edición), no el filtro actual -- si no, cambiar el filtro a mitad de la
    // edición reasignaría el producto a otra sucursal sin avisar.
    const sucursalId = editId
        ? document.getElementById('inv-edit-sucursal-id').value
        : document.getElementById('inv-sucursal-activa').value;
    const formData = new FormData();
    formData.append('sucursal_id', sucursalId);
    // El producto (nuevo o en edición) siempre queda del rubro activo -- si es
    // edición, el item ya pertenecía a este rubro porque solo se lista lo que
    // coincide con moduloActivo (ver cargarInventario).
    formData.append('modulo', moduloActivo);
    formData.append('descripcion', descripcion);
    formData.append('cantidad', cantidad || 0);
    formData.append('precio', precio || 0);
    formData.append('costo', costo || 0);
    const fotoInput = document.getElementById('inv-foto');
    if (fotoInput.files && fotoInput.files[0]) formData.append('foto', fotoInput.files[0]);

    await conBotonCargando(document.getElementById('inv-submit-btn'), '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>', async () => {
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

/* ---------- Ventas sin conexión: cola local + sincronización automática ----------
   Objetivo: un empleado nunca debe perder una venta por quedarse sin internet un
   rato. Si el POST a /ventas falla por un problema de red (no por una validación
   real del servidor), la venta se guarda en localStorage y se reintenta sola
   apenas vuelve la conexión (evento "online" + un ping de respaldo cada 45s, por
   si el navegador no dispara ese evento de forma confiable). Si al sincronizar
   el stock ya no alcanza (otra sucursal se adelantó), la venta NUNCA se descarta:
   se reintenta como "venta libre" (sin descontar inventario) con una nota en
   observaciones para que quede visible y se revise a mano -- ver
   intentarSincronizarVentasPendientes(). */

const CLAVE_VENTAS_PENDIENTES = 'rj_ventas_pendientes';

function obtenerVentasPendientes() {
    try {
        return JSON.parse(localStorage.getItem(CLAVE_VENTAS_PENDIENTES) || '[]');
    } catch (err) {
        return [];
    }
}

function guardarVentasPendientes(cola) {
    localStorage.setItem(CLAVE_VENTAS_PENDIENTES, JSON.stringify(cola));
}

// Agrega una venta a la cola local (id propio para poder identificarla en la
// tabla y borrarla de la cola después) y refresca la tabla para que aparezca al toque.
function encolarVentaPendiente(body) {
    const cola = obtenerVentasPendientes();
    cola.push({
        idLocal: 'pendiente-' + Date.now() + '-' + Math.random().toString(36).slice(2, 7),
        body,
        creadaEn: new Date().toISOString(),
        error: null,
    });
    guardarVentasPendientes(cola);
    renderVentas();
    cargarAlertas();
}

// Recorre la cola y trata de mandar cada venta. Devuelve true si algo cambió
// (para que quien llame decida si vale la pena refrescar la tabla/alertas).
async function intentarSincronizarVentasPendientes() {
    const cola = obtenerVentasPendientes();
    if (!cola.length || !navigator.onLine) return false;

    let huboCambios = false;
    const colaRestante = [];

    for (const pendiente of cola) {
        try {
            await apiFetch('/ventas', { method: 'POST', body: pendiente.body });
            huboCambios = true; // se mandó bien -- no se vuelve a agregar a colaRestante.
        } catch (err) {
            if (err.status === undefined) {
                // Sigue sin internet (o se cortó a mitad de camino): se deja tal cual para el próximo intento.
                colaRestante.push(pendiente);
                continue;
            }

            // Hubo respuesta del servidor pero la rechazó. El caso esperable es
            // "stock insuficiente" porque el producto se vendió en otro lado
            // mientras esta venta esperaba sin conexión -- ahí NUNCA se descarta,
            // se reintenta como venta libre (sin inventario_id) dejando la
            // alerta en observaciones para revisar a mano.
            const esConflictoDeStock = err.status === 422 && /stock insuficiente/i.test(err.message || '');
            if (esConflictoDeStock && pendiente.body.inventario_id && !pendiente.reintentadaSinStock) {
                const nota = '⚠️ Sincronizada sin conexión: no había stock suficiente al reconectar, no se descontó inventario. Revisar.';
                const bodyLibre = {
                    ...pendiente.body,
                    inventario_id: null,
                    observaciones: pendiente.body.observaciones ? pendiente.body.observaciones + ' — ' + nota : nota,
                };
                try {
                    await apiFetch('/ventas', { method: 'POST', body: bodyLibre });
                    huboCambios = true;
                } catch (err2) {
                    if (err2.status === undefined) {
                        colaRestante.push(pendiente); // se cortó de nuevo justo acá -- reintentar más tarde.
                    } else {
                        // Algo más rechazó incluso la versión libre: no reintentar en loop, queda visible con error para revisión manual.
                        colaRestante.push({ ...pendiente, error: err2.message || 'No se pudo sincronizar.' });
                        huboCambios = true;
                    }
                }
                continue;
            }

            // Cualquier otro rechazo del servidor: no lo vamos a resolver reintentando
            // solo -- se deja visible con el error para que alguien lo revise a mano.
            colaRestante.push({ ...pendiente, error: err.message || 'No se pudo sincronizar.' });
            huboCambios = true;
        }
    }

    guardarVentasPendientes(colaRestante);
    return huboCambios;
}

// Dispara un intento de sincronización y, si cambió algo, refresca tabla + alertas.
async function sincronizarVentasPendientesYRefrescar() {
    const huboCambios = await intentarSincronizarVentasPendientes();
    if (huboCambios) {
        await cargarVentas();
        await cargarAlertas();
    } else {
        renderVentas(); // por si la cola sigue igual, al menos repinta (ej. si se está mostrando sec-ventas).
    }
}

// Se dispara solo apenas el navegador recupera conexión.
window.addEventListener('online', sincronizarVentasPendientesYRefrescar);
// Respaldo: por si el evento "online" no llega a dispararse (pasa en algunos
// navegadores/redes), se reintenta cada 45s de todas formas.
setInterval(sincronizarVentasPendientesYRefrescar, 45000);

/* ---------- Ventas ---------- */

// Trae las ventas desde la API y las pinta en la tabla.
async function cargarVentas() {
    try {
        const params = [moduloQS()];
        const sucursalId = document.getElementById('filtro-v-sucursal').value;
        const empleadoId = document.getElementById('filtro-v-empleado').value;
        const desde = document.getElementById('filtro-v-desde').value;
        const hasta = document.getElementById('filtro-v-hasta').value;
        if (sucursalId) params.push('sucursal_id=' + sucursalId);
        if (empleadoId) params.push('user_id=' + empleadoId);
        if (desde) params.push('desde=' + desde);
        if (hasta) params.push('hasta=' + hasta);
        ventasCompleto = await apiFetch('/ventas?' + params.join('&'));
        ventasPagina = 1;
        renderVentas();
    } catch (err) { /* deja la lista como estaba si falla la carga */ }
}

// Trae y muestra el cierre de HOY (Total/Nequi/Efectivo) de la sucursal activa.
// A diferencia de la pantalla de Cierres Diarios (solo admin), esto lo ve
// cualquier rol -- la empleada necesita cuadrar caja antes de que el dueño
// pase a recoger el efectivo.
async function cargarCierreHoy() {
    try {
        let path = '/cierres/hoy?' + moduloQS();
        if (esAdmin()) {
            const sucursalId = document.getElementById('v-sucursal-activa').value;
            if (sucursalId) path += '&sucursal_id=' + sucursalId;
        }
        const cierre = await apiFetch(path);
        document.getElementById('cierre-hoy-total').textContent = '$' + Number(cierre.total).toFixed(2);
        document.getElementById('cierre-hoy-nequi').textContent = '$' + Number(cierre.total_nequi).toFixed(2);
        document.getElementById('cierre-hoy-efectivo').textContent = '$' + Number(cierre.total_efectivo).toFixed(2);
    } catch (err) { /* deja los valores como estaban si falla la carga */ }
}

// Llena el <select> de empleados del filtro de Ventas (solo admin, ya que /usuarios es admin-only).
async function cargarEmpleadosParaFiltroVentas() {
    if (!esAdmin()) return;
    const select = document.getElementById('filtro-v-empleado');
    try {
        const usuarios = await apiFetch('/usuarios');
        const seleccionActual = select.value;
        select.innerHTML = '<option value="">Todos los empleados</option>';
        usuarios.forEach(u => select.appendChild(crearOpcion(u.id, u.name + ' (' + u.username + ')')));
        select.value = seleccionActual;
    } catch (err) { /* deja el select como estaba si falla la carga */ }
}

// Vacía los 4 filtros de Ventas y vuelve a cargar la lista sin filtrar.
function limpiarFiltrosVentas() {
    document.getElementById('filtro-v-sucursal').value = '';
    document.getElementById('filtro-v-empleado').value = '';
    document.getElementById('filtro-v-desde').value = '';
    document.getElementById('filtro-v-hasta').value = '';
    cargarVentas();
}

// Descarga en CSV (se abre bien en Excel) las ventas visibles: respeta los
// mismos filtros de sucursal/empleado/fechas que están aplicados en la tabla.
async function exportarVentas() {
    try {
        const params = [moduloQS()];
        const sucursalId = document.getElementById('filtro-v-sucursal').value;
        const empleadoId = document.getElementById('filtro-v-empleado').value;
        const desde = document.getElementById('filtro-v-desde').value;
        const hasta = document.getElementById('filtro-v-hasta').value;
        if (sucursalId) params.push('sucursal_id=' + sucursalId);
        if (empleadoId) params.push('user_id=' + empleadoId);
        if (desde) params.push('desde=' + desde);
        if (hasta) params.push('hasta=' + hasta);

        const res = await fetch(API_BASE + '/ventas/exportar?' + params.join('&'), {
            headers: { Authorization: 'Bearer ' + getToken() }
        });
        if (!res.ok) throw new Error('No se pudo exportar las ventas.');
        const blob = await res.blob();
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `ventas_${new Date().toISOString().slice(0, 10)}.csv`;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    } catch (err) {
        alert(err.message || 'No se pudo exportar las ventas.');
    }
}

// Descarga un CSV de ejemplo con las columnas que espera importarVentas(),
// para que quede claro cómo llenarlo. No pega contra la API: se arma en el navegador.
function descargarPlantillaVentas() {
    const encabezado = ['Fecha', 'Producto', 'Código', 'Cantidad', 'Valor', 'Tipo', 'Método de pago', 'Observaciones'];
    const ejemplo = [new Date().toISOString().slice(0, 10), 'Reloj Casio Clásico', 'REL-C1', '1', '45.00', 'venta', 'efectivo', 'Fila de ejemplo, se puede borrar'];
    const filas = [encabezado, ejemplo].map(f => f.map(v => `"${String(v).replace(/"/g, '""')}"`).join(','));
    // BOM al inicio para que Excel detecte UTF-8 y muestre bien los acentos.
    const blob = new Blob(['﻿' + filas.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = 'plantilla_ventas.csv';
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

// Importa ventas desde un CSV (columnas Fecha, Producto, Código [opcional,
// liga a un producto de Inventario y descuenta su stock], Cantidad, Valor,
// Tipo, Método de pago, Observaciones) a la sucursal elegida arriba.
async function importarVentas(input) {
    const archivo = input.files[0];
    if (!archivo) return;

    const sucursalId = document.getElementById('v-sucursal-activa').value;
    if (!sucursalId) {
        alert('Elegí una sucursal en el selector de arriba antes de importar.');
        input.value = '';
        return;
    }

    const formData = new FormData();
    formData.append('archivo', archivo);
    formData.append('sucursal_id', sucursalId);
    formData.append('modulo', moduloActivo);

    try {
        const resultado = await apiFetch('/ventas/importar', { method: 'POST', body: formData });
        let mensaje = `Importación completa: ${resultado.creados} venta(s) creada(s).`;
        if (resultado.errores.length) mensaje += '\n\n' + resultado.errores.join('\n');
        alert(mensaje);
        await cargarVentas();
    } catch (err) {
        alert(err.message || 'No se pudo importar el archivo.');
    } finally {
        input.value = '';
    }
}

// Llena el <select> de "producto del inventario" en el formulario de Ventas, solo con productos que tengan stock.
async function cargarInventarioParaVentas() {
    const select = document.getElementById('v-inv-select');
    try {
        let path = '/inventario?' + moduloQS();
        if (esAdmin()) {
            const sucursalId = document.getElementById('v-sucursal-activa').value;
            if (sucursalId) path += '&sucursal_id=' + sucursalId;
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

// Quita una venta pendiente de la cola local sin intentar mandarla (para
// descartar un intento con error que no se va a poder resolver solo, ej. un
// campo inválido). Pide confirmación porque no hay forma de deshacerlo.
function descartarVentaPendiente(idLocal) {
    if (!confirm('¿Descartar esta venta? No se va a guardar en el sistema.')) return;
    guardarVentasPendientes(obtenerVentasPendientes().filter(p => p.idLocal !== idLocal));
    renderVentas();
    cargarAlertas();
}

// Arma la fila de una venta todavía sin sincronizar: mismas columnas que una
// fila normal (para que no "salte" la tabla), pero atenuada y con una badge
// de estado en vez de los botones de editar/eliminar -- todavía no tiene id
// del servidor, así que no se puede editar/borrar hasta que sincronice.
function filaVentaPendiente(p) {
    const v = p.body;
    const tr = document.createElement('tr');
    tr.className = 'fila-pendiente';

    const tdFecha = document.createElement('td'); tdFecha.textContent = v.fecha ? v.fecha.slice(0, 10) : new Date(p.creadaEn).toISOString().slice(0, 10);
    const tdTipo = document.createElement('td');
    const badgeTipo = document.createElement('span');
    badgeTipo.className = 'badge ' + (v.tipo === 'arreglo' ? 'badge-en_proceso' : 'badge-entregado');
    badgeTipo.textContent = v.tipo === 'arreglo' ? 'Arreglo' : 'Venta';
    tdTipo.appendChild(badgeTipo);
    const tdProd = document.createElement('td'); tdProd.textContent = v.producto;
    const tdCant = document.createElement('td'); tdCant.textContent = v.cantidad ?? '';
    const tdValor = document.createElement('td'); tdValor.textContent = v.valor != null ? '$' + v.valor : '';
    const tdMetodo = document.createElement('td'); tdMetodo.textContent = v.metodo_pago === 'nequi' ? 'Nequi' : 'Efectivo';
    const tdObs = document.createElement('td'); tdObs.textContent = v.observaciones || '';
    const tdUser = document.createElement('td'); tdUser.textContent = sesionActual ? sesionActual.username : '';

    const tdAcciones = document.createElement('td'); tdAcciones.className = 'acciones';
    const badgeEstado = document.createElement('span');
    if (p.error) {
        badgeEstado.className = 'badge badge-pendiente-error';
        badgeEstado.title = p.error;
        badgeEstado.innerHTML = '<i class="fas fa-triangle-exclamation" aria-hidden="true"></i> Error al sincronizar';
    } else {
        badgeEstado.className = 'badge badge-pendiente';
        badgeEstado.title = 'Guardada en este dispositivo, se enviará sola apenas vuelva la conexión.';
        badgeEstado.innerHTML = '<i class="fas fa-cloud-arrow-up" aria-hidden="true"></i> Pendiente de sincronizar';
    }
    const grupo = document.createElement('div');
    grupo.className = 'acciones-grupo';
    grupo.appendChild(badgeEstado);
    grupo.appendChild(crearBotonEliminar('Descartar venta pendiente de ' + v.producto, () => descartarVentaPendiente(p.idLocal)));
    tdAcciones.appendChild(grupo);

    tr.append(tdFecha, tdTipo, tdProd, tdCant, tdValor, tdMetodo, tdObs, tdUser, tdAcciones);
    return tr;
}

// Dibuja la tabla de Ventas, con botones Editar/Eliminar para cualquier rol:
// la lista que ve un empleado ya viene escopeada a su propia sucursal (ver
// VentaController::index), y el backend vuelve a validar esa sucursal en
// update()/destroy(), así que puede corregir/borrar sus propias ventas para
// cuadrar caja; el admin puede sobre cualquier sucursal. Las ventas todavía
// sin sincronizar (offline) se muestran primero, sin paginar, para que
// nunca queden escondidas en otra página.
function renderVentas() {
    const tbody = document.querySelector('#tab-ventas tbody');
    tbody.innerHTML = '';

    obtenerVentasPendientes()
        .filter(p => (p.body.modulo || 'relojeria') === moduloActivo)
        .forEach(p => tbody.appendChild(filaVentaPendiente(p)));

    paginar(ventasCompleto, ventasPagina).forEach(v => {
        const tr = document.createElement('tr');
        const tdFecha = document.createElement('td'); tdFecha.textContent = v.fecha ? v.fecha.slice(0, 10) : '';
        const tdTipo = document.createElement('td');
        const badgeTipo = document.createElement('span');
        badgeTipo.className = 'badge ' + (v.tipo === 'arreglo' ? 'badge-en_proceso' : 'badge-entregado');
        badgeTipo.textContent = v.tipo === 'arreglo' ? 'Arreglo' : 'Venta';
        tdTipo.appendChild(badgeTipo);
        const tdProd = document.createElement('td'); tdProd.textContent = v.producto;
        const tdCant = document.createElement('td'); tdCant.textContent = v.cantidad ?? '';
        const tdValor = document.createElement('td'); tdValor.textContent = v.valor != null ? '$' + v.valor : '';
        const tdMetodo = document.createElement('td'); tdMetodo.textContent = v.metodo_pago === 'nequi' ? 'Nequi' : 'Efectivo';
        const tdObs = document.createElement('td'); tdObs.textContent = v.observaciones || '';
        const tdUser = document.createElement('td'); tdUser.textContent = v.user ? v.user.username : '';
        const tdAcciones = document.createElement('td'); tdAcciones.className = 'acciones';
        const grupo = document.createElement('div');
        grupo.className = 'acciones-grupo';
        grupo.appendChild(crearBotonEditar('Editar venta de ' + v.producto, () => editarVenta(v)));
        grupo.appendChild(crearBotonEliminar('Eliminar venta de ' + v.producto, () => eliminarVenta(v.id)));
        tdAcciones.appendChild(grupo);

        tr.append(tdFecha, tdTipo, tdProd, tdCant, tdValor, tdMetodo, tdObs, tdUser, tdAcciones);
        tbody.appendChild(tr);
    });

    renderPaginacion('ventas-paginacion', ventasCompleto.length, ventasPagina, pagina => {
        ventasPagina = pagina;
        renderVentas();
    });
}

// Precarga el formulario de Ventas con los datos de la venta elegida.
function editarVenta(v) {
    mostrarFormulario('form-ventas', 'v-submit-btn');
    document.getElementById('v-edit-id').value = v.id;
    document.getElementById('v-inventario-id').value = '';
    document.getElementById('v-inv-select').value = '';
    document.getElementById('v-prod').value = v.producto;
    document.getElementById('v-cant').value = v.cantidad || '';
    document.getElementById('v-val').value = v.valor || '';
    document.getElementById('v-fecha').value = v.fecha ? v.fecha.slice(0, 10) : '';
    document.getElementById('v-tipo').value = v.tipo || 'venta';
    document.getElementById('v-metodo-pago').value = v.metodo_pago || 'efectivo';
    document.getElementById('v-obs').value = v.observaciones || '';
    actualizarTooltipBoton('v-submit-btn', 'Guardar cambios');
    document.getElementById('v-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Ventas y sale del modo edición.
function cancelarEdicionVenta() {
    document.getElementById('form-ventas').reset();
    document.getElementById('v-edit-id').value = '';
    actualizarTooltipBoton('v-submit-btn', 'Guardar venta');
    document.getElementById('v-cancelar-btn').classList.add('hidden');
    ocultarFormulario('form-ventas', 'v-submit-btn');
}

// Envía el formulario de Ventas (crea o edita según corresponda).
async function regVenta() {
    const p = document.getElementById('v-prod').value.trim();
    if (!p) return;
    const cantidad = document.getElementById('v-cant').value;
    const valor = document.getElementById('v-val').value;
    const editId = document.getElementById('v-edit-id').value;
    const inventarioId = document.getElementById('v-inventario-id').value;
    const fecha = document.getElementById('v-fecha').value;
    const tipo = document.getElementById('v-tipo').value;
    const metodoPago = document.getElementById('v-metodo-pago').value;
    const observaciones = document.getElementById('v-obs').value;

    await conBotonCargando(document.getElementById('v-submit-btn'), '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>', async () => {
        try {
            if (editId) {
                await apiFetch('/ventas/' + editId, { method: 'PATCH', body: { producto: p, cantidad, valor, fecha, tipo, metodo_pago: metodoPago, observaciones } });
                cancelarEdicionVenta();
                await cargarVentas();
                await cargarCierreHoy();
                if (inventarioId) await cargarInventarioParaVentas();
            } else {
                // Se manda siempre: si hay inventario_id el backend lo ignora y hereda
                // el módulo real del producto; en una venta libre es lo único que le
                // dice a qué rubro pertenece.
                const body = { producto: p, cantidad, valor, modulo: moduloActivo, tipo, metodo_pago: metodoPago, observaciones };
                if (fecha) body.fecha = fecha;
                if (inventarioId) body.inventario_id = inventarioId;
                if (esAdmin()) body.sucursal_id = document.getElementById('v-sucursal-activa').value;

                try {
                    await apiFetch('/ventas', { method: 'POST', body });
                    document.getElementById('form-ventas').reset();
                    ocultarFormulario('form-ventas', 'v-submit-btn');
                    alert('Venta guardada');
                    await cargarVentas();
                    await cargarCierreHoy();
                    if (inventarioId) await cargarInventarioParaVentas();
                } catch (errRed) {
                    // Sin "status" = fetch nunca llegó a golpear el servidor (sin
                    // internet, o se cortó a mitad de camino) -- no es un error de
                    // datos, es de conexión. Se guarda local en vez de perderla.
                    if (errRed.status === undefined) {
                        encolarVentaPendiente(body);
                        document.getElementById('form-ventas').reset();
                        ocultarFormulario('form-ventas', 'v-submit-btn');
                        alert('Sin conexión: la venta quedó guardada en este dispositivo y se va a enviar sola apenas vuelva internet.');
                    } else {
                        throw errRed;
                    }
                }
            }
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
        cargarCierreHoy();
    } catch (err) {
        alert('No se pudo eliminar la venta.');
    }
}

/* ---------- Reparaciones ---------- */

// Trae las reparaciones desde la API y las pinta en la lista.
async function cargarReparaciones() {
    try {
        reparacionesCompleto = await apiFetch('/reparaciones?' + moduloQS());
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

    const tbody = document.querySelector('#tab-reps tbody');
    tbody.innerHTML = '';
    paginar(filtrados, reparacionesPagina).forEach(r => {
        const tr = document.createElement('tr');

        const tdFoto = document.createElement('td');
        if (r.foto_url) {
            const img = document.createElement('img');
            img.src = r.foto_url;
            img.className = 'thumb-inv';
            img.alt = 'Foto del reloj de ' + r.cliente;
            img.onclick = () => abrirLightboxFoto(r.foto_url, img.alt);
            tdFoto.appendChild(img);
        }

        const tdCliente = document.createElement('td'); tdCliente.textContent = r.cliente;
        const tdCedula = document.createElement('td'); tdCedula.textContent = r.cedula || '—';
        const tdCelular = document.createElement('td'); tdCelular.textContent = r.telefono || '—';

        const tdModelo = document.createElement('td'); tdModelo.textContent = r.modelo || '—';

        const tdValores = document.createElement('td');
        const saldoSpan = document.createElement('span');
        saldoSpan.style.color = 'red';
        saldoSpan.textContent = `Saldo: $${r.saldo}`;
        tdValores.append(
            document.createTextNode(`Total: $${r.valor_total}`), document.createElement('br'),
            document.createTextNode(`Abono: $${r.abono}`), document.createElement('br'),
            saldoSpan
        );

        const tdSede = document.createElement('td');
        tdSede.append(document.createTextNode(r.sucursal ? r.sucursal.nombre : '—'));
        if (r.user) tdSede.append(document.createElement('br'), document.createTextNode('Por: ' + r.user.username));

        const tdEstado = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'badge badge-' + r.estado;
        badge.textContent = ESTADOS_REPARACION[r.estado] || r.estado;
        tdEstado.append(badge, document.createElement('br'), crearSelectEstado(r));

        const tdObs = document.createElement('td');
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
        tdObs.appendChild(obsWrap);

        const tdAcciones = document.createElement('td'); tdAcciones.className = 'acciones';
        if (esAdmin()) {
            const grupo = document.createElement('div');
            grupo.className = 'acciones-grupo';
            grupo.appendChild(crearBotonEditar('Editar reparación de ' + r.cliente, () => editarReparacion(r)));
            grupo.appendChild(crearBotonEliminar('Eliminar reparación de ' + r.cliente, () => eliminarReparacion(r.id)));
            tdAcciones.appendChild(grupo);
        }

        tr.append(tdFoto, tdCliente, tdCedula, tdCelular, tdModelo, tdValores, tdSede, tdEstado, tdObs, tdAcciones);
        tbody.appendChild(tr);
    });

    renderPaginacion('reps-paginacion', filtrados.length, reparacionesPagina, pagina => {
        reparacionesPagina = pagina;
        renderReparaciones();
    });
}

// Trae de nuevo la reparación por id desde la API (no el objeto cacheado de la última
// carga de lista), para no pisar con datos viejos algo que otro usuario haya cambiado
// mientras tanto (ver cambiarEstadoReparacion/guardarObservaciones).
async function obtenerReparacionFresca(id) {
    const lista = await apiFetch('/reparaciones?' + moduloQS());
    return lista.find(r => r.id === id);
}

// Guarda el nuevo estado elegido en el select, reenviando también los demás campos que la validación de admin exige en el backend.
async function cambiarEstadoReparacion(reparacion, nuevoEstado) {
    try {
        const fresca = await obtenerReparacionFresca(reparacion.id) || reparacion;
        await apiFetch('/reparaciones/' + reparacion.id, {
            method: 'PUT',
            body: {
                estado: nuevoEstado,
                observaciones: fresca.observaciones || '',
                cliente: fresca.cliente,
                modelo: fresca.modelo,
                valor_total: fresca.valor_total,
                abono: fresca.abono,
                fecha: fresca.fecha,
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
        const fresca = await obtenerReparacionFresca(reparacion.id) || reparacion;
        await apiFetch('/reparaciones/' + reparacion.id, {
            method: 'PUT',
            body: {
                estado: fresca.estado,
                observaciones: texto,
                cliente: fresca.cliente,
                modelo: fresca.modelo,
                valor_total: fresca.valor_total,
                abono: fresca.abono,
                fecha: fresca.fecha,
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
    mostrarFormulario('form-reparaciones', 'r-submit-btn');
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
    actualizarTooltipBoton('r-submit-btn', 'Guardar cambios');
    document.getElementById('r-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Reparaciones y sale del modo edición.
function cancelarEdicionReparacion() {
    reparacionEditando = null;
    document.getElementById('form-reparaciones').reset();
    document.getElementById('r-edit-id').value = '';
    actualizarTooltipBoton('r-submit-btn', 'Registrar reparación');
    document.getElementById('r-cancelar-btn').classList.add('hidden');
    ocultarFormulario('form-reparaciones', 'r-submit-btn');
}

// Envía el formulario de Reparaciones (crea o edita), como multipart/form-data por la foto.
async function regRep() {
    const c = document.getElementById('r-cli').value.trim();
    if (!c) return;

    const editId = document.getElementById('r-edit-id').value;
    const formData = new FormData();
    formData.append('modulo', moduloActivo);
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

    await conBotonCargando(document.getElementById('r-submit-btn'), '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>', async () => {
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
                ocultarFormulario('form-reparaciones', 'r-submit-btn');
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

/* ---------- Movimientos ---------- */

// Llena el <select> de producto para Movimientos: admin ve todo el catálogo
// (de cualquier sucursal, con el nombre de sede en la etiqueta); empleado
// solo ve el suyo (la API ya lo filtra sola, igual que en Inventario).
async function cargarInventarioParaMovimientos() {
    const select = document.getElementById('mov-inventario-id');
    try {
        const items = await apiFetch('/inventario?' + moduloQS());
        const seleccionActual = select.value;
        select.innerHTML = '<option value="">-- Selecciona un producto --</option>';
        items.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.id;
            let etiqueta = `${item.descripcion} (stock: ${item.cantidad})`;
            if (esAdmin() && item.sucursal_id) {
                const sucursal = sucursalesCache.find(s => s.id === item.sucursal_id);
                if (sucursal) etiqueta += ` — ${sucursal.nombre}`;
            }
            opt.textContent = etiqueta;
            select.appendChild(opt);
        });
        select.value = seleccionActual;
    } catch (err) { /* deja el select como estaba si falla la carga */ }
}

// Trae el historial de movimientos (scoped a la sucursal propia si es empleado) y lo pinta en la tabla.
async function cargarMovimientos() {
    try {
        movimientosCompleto = await apiFetch('/movimientos?' + moduloQS());
        movimientosPagina = 1;
        renderMovimientos();
    } catch (err) { /* deja la tabla como estaba si falla la carga */ }
}

// Dibuja la tabla de Movimientos, con badge de color por tipo y botón Eliminar (revierte el stock) solo para admin.
function renderMovimientos() {
    const tbody = document.querySelector('#tab-movimientos tbody');
    tbody.innerHTML = '';
    paginar(movimientosCompleto, movimientosPagina).forEach(m => {
        const tr = document.createElement('tr');
        const tdFecha = document.createElement('td'); tdFecha.textContent = m.fecha ? m.fecha.slice(0, 10) : '';
        const tdTipo = document.createElement('td');
        const badge = document.createElement('span');
        badge.className = 'badge ' + (m.tipo === 'entrada' ? 'badge-entregado' : 'badge-pendiente');
        badge.textContent = m.tipo === 'entrada' ? 'Entrada' : 'Salida';
        tdTipo.appendChild(badge);
        const tdProd = document.createElement('td'); tdProd.textContent = m.inventario ? m.inventario.descripcion : '(producto eliminado)';
        const tdCant = document.createElement('td'); tdCant.textContent = m.cantidad;
        const tdMotivo = document.createElement('td'); tdMotivo.textContent = m.motivo;
        const tdUser = document.createElement('td'); tdUser.textContent = m.user ? m.user.username : '';
        const tdAcciones = document.createElement('td'); tdAcciones.className = 'acciones';

        if (esAdmin()) {
            tdAcciones.appendChild(crearBotonEliminar('Eliminar movimiento de ' + (m.inventario ? m.inventario.descripcion : ''), () => eliminarMovimiento(m.id)));
        }

        tr.append(tdFecha, tdTipo, tdProd, tdCant, tdMotivo, tdUser, tdAcciones);
        tbody.appendChild(tr);
    });

    renderPaginacion('movimientos-paginacion', movimientosCompleto.length, movimientosPagina, pagina => {
        movimientosPagina = pagina;
        renderMovimientos();
    });
}

// Envía el formulario de Movimientos (siempre crea uno nuevo, no hay edición: para corregir un error se elimina y se revierte el stock).
async function regMovimiento() {
    const inventarioId = document.getElementById('mov-inventario-id').value;
    const tipo = document.getElementById('mov-tipo').value;
    const cantidad = document.getElementById('mov-cantidad').value;
    const motivo = document.getElementById('mov-motivo').value.trim();
    if (!inventarioId || !motivo) {
        alert('Selecciona un producto y escribe el motivo.');
        return;
    }

    await conBotonCargando(document.getElementById('mov-submit-btn'), '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i>', async () => {
        try {
            await apiFetch('/movimientos', { method: 'POST', body: { inventario_id: inventarioId, tipo, cantidad, motivo } });
            document.getElementById('form-movimientos').reset();
            ocultarFormulario('form-movimientos', 'mov-submit-btn');
            await cargarMovimientos();
            await cargarInventarioParaMovimientos();
        } catch (err) {
            alert(err.message || 'No se pudo registrar el movimiento.');
        }
    });
}

// Pide confirmación y borra un movimiento (revierte su efecto sobre el stock, solo admin).
async function eliminarMovimiento(id) {
    if (!confirm('¿Eliminar este movimiento? Se revertirá su efecto sobre el stock.')) return;
    try {
        await apiFetch('/movimientos/' + id, { method: 'DELETE' });
        await cargarMovimientos();
        await cargarInventarioParaMovimientos();
    } catch (err) {
        alert(err.message || 'No se pudo eliminar el movimiento.');
    }
}

/* ---------- Actividad (solo admin) ---------- */

// Trae el feed de actividad (ventas + reparaciones + movimientos de todas las sucursales) según los filtros elegidos, y lo pinta.
async function cargarActividad() {
    const ul = document.getElementById('lista-actividad');
    if (!esAdmin()) {
        ul.innerHTML = '';
        return;
    }
    const params = [moduloQS()];
    const sucursalId = document.getElementById('act-sucursal').value;
    const desde = document.getElementById('act-desde').value;
    const hasta = document.getElementById('act-hasta').value;
    if (sucursalId) params.push('sucursal_id=' + sucursalId);
    if (desde) params.push('desde=' + desde);
    if (hasta) params.push('hasta=' + hasta);
    ul.innerHTML = '<li>Cargando...</li>';
    try {
        const path = '/actividad?' + params.join('&');
        renderActividad(await apiFetch(path));
    } catch (err) {
        ul.innerHTML = '<li>No se pudo cargar la actividad.</li>';
    }
}

// Ícono + color según el tipo de evento del feed de Actividad.
const ICONOS_ACTIVIDAD = {
    venta_creado: { icono: 'fa-shopping-cart', clase: 'badge-entregado' },
    venta_modificado: { icono: 'fa-pen', clase: 'badge-en_proceso' },
    venta_eliminado: { icono: 'fa-trash', clase: 'badge-pendiente' },
    reparacion: { icono: 'fa-tools', clase: 'badge-en_proceso' },
    movimiento_entrada: { icono: 'fa-arrow-down', clase: 'badge-entregado' },
    movimiento_salida: { icono: 'fa-arrow-up', clase: 'badge-pendiente' },
};

// Dibuja el feed de Actividad: un ítem por evento, con ícono por tipo, sucursal, usuario y fecha.
function renderActividad(eventos) {
    const ul = document.getElementById('lista-actividad');
    ul.innerHTML = '';

    if (!eventos.length) {
        const li = document.createElement('li');
        li.textContent = 'Sin actividad registrada en este período.';
        ul.appendChild(li);
        return;
    }

    eventos.forEach(ev => {
        const li = document.createElement('li');
        const contenido = document.createElement('div');
        contenido.className = 'li-content';

        const meta = ICONOS_ACTIVIDAD[ev.tipo] || { icono: 'fa-circle', clase: '' };
        const badge = document.createElement('span');
        badge.className = 'badge ' + meta.clase;
        badge.innerHTML = `<i class="fas ${meta.icono}" aria-hidden="true"></i>`;

        const texto = document.createElement('span');
        let linea = ev.descripcion;
        if (ev.monto !== null && ev.monto !== undefined) linea += ` — $${ev.monto}`;
        texto.appendChild(document.createTextNode(linea));
        texto.appendChild(document.createElement('br'));
        let meta2 = ev.fecha || '';
        if (ev.sucursal) meta2 += ' — ' + ev.sucursal.nombre;
        if (ev.usuario) meta2 += ' — ' + ev.usuario;
        const spanMeta = document.createElement('span');
        spanMeta.style.color = '#666';
        spanMeta.style.fontSize = '13px';
        spanMeta.textContent = meta2;
        texto.appendChild(spanMeta);

        contenido.append(badge, document.createTextNode(' '), texto);
        li.appendChild(contenido);
        ul.appendChild(li);
    });
}

/* ---------- Reportes ---------- */

// Trae las estadísticas agregadas (ventas, reparaciones, inventario) y las pinta en las tarjetas de Reportes; si no es admin, muestra un aviso en vez de la data.
async function cargarReportes() {
    const el = document.getElementById('reportes-contenido');
    document.getElementById('reportes-detalle').innerHTML = '';
    if (!esAdmin()) {
        el.textContent = 'Esta sección es solo para el administrador.';
        return;
    }
    try {
        const periodo = document.getElementById('reportes-periodo').value;
        const sucursalId = document.getElementById('reportes-sucursal').value;
        let path = '/reportes?' + moduloQS() + '&periodo=' + periodo;
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
            ['Sucursal', 'Ventas', 'Reparaciones pendientes', 'Productos', 'Detalle'].forEach(titulo => {
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
                const tdDetalle = document.createElement('td');
                tdDetalle.className = 'acciones';
                if (s.reparaciones_pendientes > 0) {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'edit-btn';
                    btn.textContent = 'Ver';
                    btn.setAttribute('aria-label', 'Ver detalle de reparaciones pendientes de ' + s.nombre);
                    btn.addEventListener('click', () => verDetalleReparacionesPendientes(s.id, s.nombre));
                    tdDetalle.appendChild(btn);
                }
                tr.appendChild(tdDetalle);
                tbody.appendChild(tr);
            });
            tabla.appendChild(tbody);
            el.appendChild(tabla);
        }
    } catch (err) {
        el.textContent = 'No se pudieron cargar los reportes.';
    }
}

// Trae y muestra el detalle (cliente, modelo, estado, diagnóstico) de las
// reparaciones pendientes (cualquier estado menos "entregado") de una
// sucursal -- lo que el desglose de Reportes solo resume como un número.
async function verDetalleReparacionesPendientes(sucursalId, nombreSucursal) {
    const cont = document.getElementById('reportes-detalle');
    cont.textContent = 'Cargando...';
    try {
        const periodo = document.getElementById('reportes-periodo').value;
        let path = '/reparaciones?' + moduloQS() + '&sucursal_id=' + sucursalId;
        const reparaciones = await apiFetch(path);
        const pendientes = reparaciones
            .filter(r => r.estado !== 'entregado')
            .filter(r => periodo !== 'mes' || (r.fecha && r.fecha.slice(0, 10) >= new Date(new Date().getFullYear(), new Date().getMonth(), 1).toISOString().slice(0, 10)));
        renderDetalleReparacionesPendientes(nombreSucursal, pendientes);
    } catch (err) {
        cont.textContent = 'No se pudo cargar el detalle de reparaciones pendientes.';
    }
}

// Dibuja la lista de reparaciones pendientes de una sucursal: quién es el cliente,
// qué reloj dejó, en qué estado está, y el diagnóstico/motivo (observaciones) --
// o sea, para qué lo dejaron.
function renderDetalleReparacionesPendientes(nombreSucursal, reparaciones) {
    const cont = document.getElementById('reportes-detalle');
    cont.innerHTML = '';

    const titulo = document.createElement('h3');
    titulo.textContent = 'Reparaciones pendientes — ' + nombreSucursal;
    cont.appendChild(titulo);

    if (!reparaciones.length) {
        const p = document.createElement('p');
        p.textContent = 'No hay reparaciones pendientes en esta sucursal.';
        cont.appendChild(p);
        return;
    }

    const ul = document.createElement('ul');
    reparaciones.forEach(r => {
        const li = document.createElement('li');
        const texto = document.createElement('span');
        const lCliente = document.createElement('strong'); lCliente.textContent = r.cliente;
        texto.append(lCliente);
        if (r.modelo) texto.append(document.createTextNode(' — ' + r.modelo));
        const badge = document.createElement('span');
        badge.className = 'badge badge-' + r.estado;
        badge.style.marginLeft = '8px';
        badge.textContent = ESTADOS_REPARACION[r.estado] || r.estado;
        texto.append(document.createTextNode(' '), badge);
        if (r.fecha) texto.append(document.createElement('br'), document.createTextNode('Dejado el: ' + r.fecha.slice(0, 10)));
        // "observaciones" es el diagnóstico que se anota al revisar el reloj --
        // la respuesta más cercana a "para qué lo dejaron" que guarda el sistema.
        texto.append(document.createElement('br'), document.createTextNode('Motivo/diagnóstico: ' + (r.observaciones || '(sin anotar)')));
        li.appendChild(texto);
        ul.appendChild(li);
    });
    cont.appendChild(ul);
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
    mostrarFormulario('form-sucursal', 'suc-submit-btn');
    document.getElementById('suc-edit-id').value = s.id;
    document.getElementById('suc-nombre').value = s.nombre;
    document.getElementById('suc-direccion').value = s.direccion || '';
    document.getElementById('suc-telefono').value = s.telefono || '';
    actualizarTooltipBoton('suc-submit-btn', 'Guardar cambios');
    document.getElementById('suc-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Sucursales y sale del modo edición.
function cancelarEdicionSucursal() {
    document.getElementById('form-sucursal').reset();
    document.getElementById('suc-edit-id').value = '';
    actualizarTooltipBoton('suc-submit-btn', 'Agregar sucursal');
    document.getElementById('suc-cancelar-btn').classList.add('hidden');
    ocultarFormulario('form-sucursal', 'suc-submit-btn');
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
        if (!u.aprobado) {
            const badge = document.createElement('span');
            badge.className = 'badge badge-pendiente';
            badge.textContent = 'Pendiente de aprobación';
            grupo.appendChild(badge);

            const btnAprobar = document.createElement('button');
            btnAprobar.type = 'button';
            btnAprobar.className = 'back-btn';
            btnAprobar.textContent = 'Aprobar';
            btnAprobar.addEventListener('click', () => aprobarUsuario(u));
            grupo.appendChild(btnAprobar);
        }
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
    mostrarFormulario('form-usuario', 'usu-submit-btn');
    document.getElementById('usu-edit-id').value = u.id;
    document.getElementById('usu-nombre').value = u.name;
    document.getElementById('usu-username').value = u.username;
    document.getElementById('usu-password').value = '';
    document.getElementById('usu-password').placeholder = 'Nueva contraseña (dejar vacío para no cambiar)';
    document.getElementById('usu-tipo').value = u.tipo;
    actualizarVisibilidadSucursalUsuario();
    document.getElementById('usu-sucursal').value = u.sucursal ? String(u.sucursal.id) : '';
    actualizarTooltipBoton('usu-submit-btn', 'Guardar cambios');
    document.getElementById('usu-cancelar-btn').classList.remove('hidden');
}

// Limpia el formulario de Usuarios y sale del modo edición.
function cancelarEdicionUsuario() {
    document.getElementById('form-usuario').reset();
    document.getElementById('usu-edit-id').value = '';
    document.getElementById('usu-password').placeholder = 'Contraseña';
    actualizarVisibilidadSucursalUsuario();
    actualizarTooltipBoton('usu-submit-btn', 'Crear usuario');
    document.getElementById('usu-cancelar-btn').classList.add('hidden');
    ocultarFormulario('form-usuario', 'usu-submit-btn');
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

// Aprueba una cuenta creada por autoregistro público, para que ya pueda iniciar sesión.
async function aprobarUsuario(u) {
    try {
        await apiFetch('/usuarios/' + u.id + '/aprobar', { method: 'POST' });
        await cargarUsuariosAdmin();
    } catch (err) {
        alert(err.message || 'No se pudo aprobar el usuario.');
    }
}

/* ---------- Cierres Diarios ---------- */

// Arma el query string de sucursal para las llamadas de Cierres Diarios.
function parametrosCierre() {
    const params = [moduloQS()];
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
        const tdNequi = document.createElement('td'); tdNequi.textContent = '$' + d.total_nequi;
        const tdEfectivo = document.createElement('td'); tdEfectivo.textContent = '$' + d.total_efectivo;
        const tdDetalle = document.createElement('td'); tdDetalle.className = 'acciones';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'edit-btn';
        btn.textContent = 'Ver';
        btn.setAttribute('aria-label', 'Ver detalle del ' + d.dia);
        btn.addEventListener('click', () => verDetalleDia(d.dia));
        tdDetalle.appendChild(btn);
        tr.append(tdFecha, tdCant, tdTotal, tdNequi, tdEfectivo, tdDetalle);
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
        const path = '/cierres/' + dia + '?' + moduloQS() + (sucursalId ? '&sucursal_id=' + sucursalId : '');
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
    let totalNequi = 0;
    ventas.forEach(v => {
        const li = document.createElement('li');
        let texto = v.producto;
        if (v.cantidad) texto += ' (x' + v.cantidad + ')';
        texto += ' - $' + v.valor + ' (' + (v.metodo_pago === 'nequi' ? 'Nequi' : 'Efectivo') + ')';
        if (v.sucursal) texto += ' — ' + v.sucursal.nombre;
        if (v.user) texto += ' — registrado por ' + v.user.username;
        const span = document.createElement('span');
        span.textContent = texto;
        li.appendChild(span);
        ul.appendChild(li);
        total += parseFloat(v.valor || 0);
        if (v.metodo_pago === 'nequi') totalNequi += parseFloat(v.valor || 0);
    });
    cont.appendChild(ul);

    // Efectivo = total - Nequi (no hay nada más que restar: son los dos únicos métodos de pago).
    const totalEfectivo = total - totalNequi;
    const totalP = document.createElement('p');
    totalP.style.fontWeight = 'bold';
    totalP.innerHTML = `Total del día: $${total.toFixed(2)}<br>Nequi: $${totalNequi.toFixed(2)}<br>Efectivo: $${totalEfectivo.toFixed(2)}`;
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
    let path = '/cierres/exportar?' + moduloQS() + '&desde=' + desde + '&hasta=' + hasta;
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

/* ---------- Mantenimiento: Módulos por sucursal (Relojería siempre, Joyería opcional) ---------- */

// Trae las sucursales y las pinta con un switch para prender/apagar Joyería en cada una.
async function cargarModulosSucursal() {
    try {
        renderModulosSucursal(await apiFetch('/sucursales'));
    } catch (err) {
        document.getElementById('lista-modulos-sucursal').innerHTML = '';
    }
}

// Dibuja la lista de sucursales con su switch de Joyería (Relojería no tiene switch: está activa siempre, en todas).
function renderModulosSucursal(sucursales) {
    const ul = document.getElementById('lista-modulos-sucursal');
    ul.innerHTML = '';
    sucursales.forEach(s => {
        const li = document.createElement('li');
        const span = document.createElement('span');
        span.textContent = s.nombre;
        li.appendChild(span);

        const wrap = document.createElement('label');
        wrap.className = 'switch-wrap';
        const texto = document.createElement('span');
        texto.textContent = 'Joyería';
        texto.style.fontSize = '13px';

        const switchEl = document.createElement('span');
        switchEl.className = 'switch';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.checked = !!s.joyeria_habilitada;
        input.setAttribute('aria-label', 'Joyería habilitada en ' + s.nombre);
        input.addEventListener('change', () => toggleJoyeriaSucursal(s, input));
        const slider = document.createElement('span');
        slider.className = 'switch-slider';
        switchEl.append(input, slider);

        wrap.append(texto, switchEl);
        li.appendChild(wrap);
        ul.appendChild(li);
    });
}

// Prende/apaga Joyería para una sucursal puntual; si falla, revierte el switch visualmente.
async function toggleJoyeriaSucursal(sucursal, input) {
    const nuevoValor = input.checked;
    try {
        await apiFetch('/sucursales/' + sucursal.id, {
            method: 'PUT',
            body: { nombre: sucursal.nombre, direccion: sucursal.direccion, telefono: sucursal.telefono, joyeria_habilitada: nuevoValor },
        });
        sucursal.joyeria_habilitada = nuevoValor;
        // Si el usuario logueado es de esta sucursal, aplicarPermisosModulo() necesita
        // el dato fresco -- se actualiza también en la sesión guardada.
        if (sesionActual && sesionActual.sucursal && sesionActual.sucursal.id === sucursal.id) {
            sesionActual.sucursal.joyeria_habilitada = nuevoValor;
            localStorage.setItem('rj_user', JSON.stringify(sesionActual));
            aplicarPermisosModulo();
        }
    } catch (err) {
        input.checked = !nuevoValor;
        alert(err.message || 'No se pudo actualizar el módulo de esta sucursal.');
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
        a.download = `backup_relojeria_joyeria_yimmy_${fecha}.sql`;
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

// Si había un correo recordado de una sesión anterior, lo precarga y marca el checkbox.
const emailRecordado = localStorage.getItem('rj_email_recordado');
if (emailRecordado) {
    document.getElementById('login-email').value = emailRecordado;
    document.getElementById('login-recordarme').checked = true;
}

aplicarTemaGuardado();
cargarSucursales();
restaurarSesion();
