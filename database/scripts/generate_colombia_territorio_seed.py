#!/usr/bin/env python3
"""
Genera INSERT SQL para departamento, municipio, zona (urbana/rural por municipio)
y ejemplos (comunas Medellín/Bogotá, barrios, corregimientos y veredas Medellín),
a partir de database/seeds/source/divipola_municipios.csv (datos.gov.co / DANE).
"""
from __future__ import annotations

import argparse
import csv
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]


def esc(s: str) -> str:
    return s.replace("\\", "\\\\").replace("'", "''")


def append_ejemplos_urbano_rural(lines: list[str]) -> None:
    """Comunas / barrios (ejemplos urbanos) y corregimiento / vereda (ejemplos rurales)."""
    lines.append("/* --- Comunas oficiales Medellín (05001), zona urbana --- */")
    med = [
        ("01", "Popular"),
        ("02", "Santa Cruz"),
        ("03", "Manrique"),
        ("04", "Aranjuez"),
        ("05", "Castilla"),
        ("06", "Doce de Octubre"),
        ("07", "Robledo"),
        ("08", "Villa Hermosa"),
        ("09", "Buenos Aires"),
        ("10", "La Candelaria"),
        ("11", "Laureles — Estadio"),
        ("12", "La América"),
        ("13", "San Javier"),
        ("14", "El Poblado"),
        ("15", "Guayabal"),
        ("16", "Belén"),
    ]
    unions = " UNION ALL ".join(
        f"SELECT '{c}' AS codigo, '{esc(n)}' AS nombre" for c, n in med
    )
    lines.append(
        "INSERT INTO `comuna` (`zona_id`, `codigo`, `nombre`, `estado_id`) "
        "SELECT z.`id`, v.`codigo`, v.`nombre`, 1 "
        "FROM `zona` z JOIN `municipio` m ON m.`id` = z.`municipio_id` "
        f"CROSS JOIN ({unions}) AS v "
        "WHERE m.`codigo` = '05001' AND z.`tipo` = 'urbana' "
        "ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")

    lines.append("/* --- Localidades Bogotá D.C. (11001) como comunas, zona urbana --- */")
    bog = [
        ("01", "Usaquén"),
        ("02", "Chapinero"),
        ("03", "Santa Fe"),
        ("04", "San Cristóbal"),
        ("05", "Usme"),
        ("06", "Tunjuelito"),
        ("07", "Bosa"),
        ("08", "Kennedy"),
        ("09", "Fontibón"),
        ("10", "Engativá"),
        ("11", "Suba"),
        ("12", "Barrios Unidos"),
        ("13", "Teusaquillo"),
        ("14", "Los Mártires"),
        ("15", "Antonio Nariño"),
        ("16", "Puente Aranda"),
        ("17", "La Candelaria"),
        ("18", "Rafael Uribe Uribe"),
        ("19", "Ciudad Bolívar"),
        ("20", "Sumapaz"),
    ]
    unions_b = " UNION ALL ".join(
        f"SELECT '{c}' AS codigo, '{esc(n)}' AS nombre" for c, n in bog
    )
    lines.append(
        "INSERT INTO `comuna` (`zona_id`, `codigo`, `nombre`, `estado_id`) "
        "SELECT z.`id`, v.`codigo`, v.`nombre`, 1 "
        "FROM `zona` z JOIN `municipio` m ON m.`id` = z.`municipio_id` "
        f"CROSS JOIN ({unions_b}) AS v "
        "WHERE m.`codigo` = '11001' AND z.`tipo` = 'urbana' "
        "ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")

    lines.append("/* --- Barrios ejemplo (Medellín, comuna Popular) --- */")
    lines.append(
        "INSERT INTO `barrio` (`comuna_id`, `codigo`, `nombre`, `estado_id`) "
        "SELECT c.`id`, v.`codigo`, v.`nombre`, 1 FROM `comuna` c "
        "JOIN `zona` z ON z.`id` = c.`zona_id` JOIN `municipio` m ON m.`id` = z.`municipio_id` "
        "CROSS JOIN ("
        "SELECT '001' AS codigo, 'Popular' AS nombre UNION ALL "
        "SELECT '002', 'Villa del Socorro' UNION ALL "
        "SELECT '003', 'San Pablo'"
        ") AS v "
        "WHERE m.`codigo` = '05001' AND z.`tipo` = 'urbana' AND c.`codigo` = '01' "
        "ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")

    lines.append("/* --- Corregimientos ejemplo (Medellín, zona rural) --- */")
    corr = [
        ("01", "San Sebastián de Palmitas"),
        ("02", "San Cristóbal"),
        ("03", "Altavista"),
        ("04", "San Antonio de Prado"),
        ("05", "Santa Elena"),
    ]
    unions_c = " UNION ALL ".join(
        f"SELECT '{c}' AS codigo, '{esc(n)}' AS nombre" for c, n in corr
    )
    lines.append(
        "INSERT INTO `corregimiento` (`zona_id`, `codigo`, `nombre`, `estado_id`) "
        "SELECT z.`id`, v.`codigo`, v.`nombre`, 1 "
        "FROM `zona` z JOIN `municipio` m ON m.`id` = z.`municipio_id` "
        f"CROSS JOIN ({unions_c}) AS v "
        "WHERE m.`codigo` = '05001' AND z.`tipo` = 'rural' "
        "ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")

    lines.append("/* --- Veredas ejemplo (corregimiento Santa Elena, Medellín) --- */")
    lines.append(
        "INSERT INTO `vereda` (`corregimiento_id`, `codigo`, `nombre`, `estado_id`) "
        "SELECT co.`id`, v.`codigo`, v.`nombre`, 1 FROM `corregimiento` co "
        "JOIN `zona` z ON z.`id` = co.`zona_id` JOIN `municipio` m ON m.`id` = z.`municipio_id` "
        "CROSS JOIN ("
        "SELECT '01' AS codigo, 'El Plan' AS nombre UNION ALL "
        "SELECT '02', 'El Picacho' UNION ALL "
        "SELECT '03', 'La Palma'"
        ") AS v "
        "WHERE m.`codigo` = '05001' AND z.`tipo` = 'rural' AND co.`codigo` = '05' "
        "ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")


def load_departments_and_municipios(csv_path: Path):
    depts: dict[str, str] = {}
    munis: list[tuple[str, str, str]] = []
    with csv_path.open(encoding="utf-8-sig", newline="") as f:
        r = csv.DictReader(f)
        for row in r:
            cd = row["Código Departamento"].strip()
            nd = row["Nombre Departamento"].strip()
            cm = row["Código Municipio"].strip()
            nm = row["Nombre Municipio"].strip()
            depts[cd] = nd
            munis.append((cd, cm, nm))
    return depts, munis


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument(
        "--csv",
        type=Path,
        default=ROOT / "database/seeds/source/divipola_municipios.csv",
        help="Ruta al CSV DIVIPOLA municipios",
    )
    ap.add_argument("-o", "--output", type=Path, help="Archivo SQL de salida (stdout si omitido)")
    args = ap.parse_args()

    if not args.csv.is_file():
        print(f"No existe CSV: {args.csv}", file=sys.stderr)
        return 1

    depts, munis = load_departments_and_municipios(args.csv)

    lines: list[str] = []
    lines.append("/* Bloque generado por database/scripts/generate_colombia_territorio_seed.py */")
    lines.append("")
    lines.append("SET @pais_co_id := (SELECT id FROM pais WHERE codigo_alpha2 = 'CO' LIMIT 1);")
    lines.append("")
    lines.append("/* --- Departamentos Colombia (DIVIPOLA) --- */")
    lines.append(
        "INSERT INTO `departamento` (`pais_id`, `codigo`, `nombre`, `estado_id`) VALUES"
    )
    dept_rows = []
    for codigo in sorted(depts.keys(), key=lambda x: (len(x), x)):
        nombre = esc(depts[codigo])
        dept_rows.append(f"    (@pais_co_id, '{esc(codigo)}', '{nombre}', 1)")
    lines.append(",\n".join(dept_rows))
    lines.append(
        "ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")

    # Municipios en lotes
    chunk = 150
    lines.append("/* --- Municipios Colombia (DIVIPOLA) --- */")
    for i in range(0, len(munis), chunk):
        batch = munis[i : i + chunk]
        lines.append(
            "INSERT INTO `municipio` (`departamento_id`, `codigo`, `nombre`, `estado_id`)"
        )
        lines.append(
            "SELECT d.id, v.codigo, v.nombre, 1 FROM ("
        )
        sel_parts = []
        for cdpto, cmuni, nmuni in batch:
            sel_parts.append(
                f"SELECT '{esc(cdpto)}' AS cdpto, '{esc(cmuni)}' AS codigo, '{esc(nmuni)}' AS nombre"
            )
        lines.append(" UNION ALL ".join(sel_parts))
        lines.append(
            ") AS v JOIN `departamento` d ON d.`codigo` = v.`cdpto` AND d.`pais_id` = @pais_co_id "
            "ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
        )
        lines.append("")

    lines.append("/* --- Zona urbana / rural por municipio (plantilla territorial) --- */")
    lines.append(
        "INSERT INTO `zona` (`municipio_id`, `tipo`, `codigo`, `nombre`, `estado_id`)"
        " SELECT m.`id`, 'urbana', 'U',"
        " SUBSTRING(CONCAT('Zona urbana — ', m.`nombre`), 1, 200), 1 FROM `municipio` m"
        " JOIN `departamento` d ON d.`id` = m.`departamento_id` AND d.`pais_id` = @pais_co_id"
        " ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")
    lines.append(
        "INSERT INTO `zona` (`municipio_id`, `tipo`, `codigo`, `nombre`, `estado_id`)"
        " SELECT m.`id`, 'rural', 'R',"
        " SUBSTRING(CONCAT('Zona rural — ', m.`nombre`), 1, 200), 1 FROM `municipio` m"
        " JOIN `departamento` d ON d.`id` = m.`departamento_id` AND d.`pais_id` = @pais_co_id"
        " ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);"
    )
    lines.append("")

    append_ejemplos_urbano_rural(lines)

    out = "\n".join(lines)
    if args.output:
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(out, encoding="utf-8")
    else:
        sys.stdout.write(out)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
