<?php

namespace Modules\Core\Enums;

enum ProvinciaMunicipioEnum: string
{
    case Bengo = 'Bengo';
    case Benguela = 'Benguela';
    case Bie = 'Bié';
    case Cabinda = 'Cabinda';
    case Cuando = 'Cuando';
    case CuanzaNorte = 'Cuanza Norte';
    case CuanzaSul = 'Cuanza Sul';
    case Cubango = 'Cubango';
    case Cunene = 'Cunene';
    case Huambo = 'Huambo';
    case Huila = 'Huíla';
    case IcoloEBengo = 'Icolo e Bengo';
    case Luanda = 'Luanda';
    case LundaNorte = 'Lunda Norte';
    case LundaSul = 'Lunda Sul';
    case Malanje = 'Malanje';
    case Moxico = 'Moxico';
    case MoxicoLeste = 'Moxico Leste';
    case Namibe = 'Namibe';
    case Uige = 'Uíge';
    case Zaire = 'Zaire';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function municipios(): array
    {
        return self::mapMunicipios()[$this->value] ?? [];
    }

    public static function allMunicipios(): array
    {
        return self::mapMunicipios();
    }

    private static function mapMunicipios(): array
    {
        return [
            'Bengo' => ['Dande', 'Quibaxe', 'Muxaluando', 'Bula Atumba', 'Ambriz', 'Pango Aluquém', 'Barra do Dande', 'Piri', 'Quicunzo', 'Nambuangongo', 'Úcua', 'Panguíla'],
            'Benguela' => ['Benguela', 'Ganda', 'Lobito', 'Catumbela', 'Bocoio', 'Balombo', 'Cubal', 'Baía Farta', 'Caimbambo', 'Chongorói', 'Egito Praia', 'Chindumbo', 'Dombe Grande', 'Capupa', 'Biópio', 'Chila', 'Chicuma', 'Babaera', 'Iambala', 'Catengue', 'Bolonguera', 'Canhamela', 'Navegantes'],
            'Bié' => ['Andulo', 'Chitembo', 'Cuito', 'Camacupa', 'Chinguar', 'Catabola', 'Cunhinga', 'Cuemba', 'Nharêa', 'Luando', 'Ringoma', 'Mumbué', 'Calucinga', 'Chicala', 'Chipeta', 'Umpulo', 'Lúbia', 'Cambândua', 'Belo Horizonte'],
            'Cabinda' => ['Cabinda', 'Cacongo', 'Buco Zau', 'Belize', 'Miconje', 'Massabi', 'Necuto', 'Tando Zinze', 'Liambo', 'Ngoio'],
            'Cuando' => ['Cuito Cuanavale', 'Dirico', 'Mavinga', 'Rivungo', 'Xipundo', 'Dima', 'Luiana', 'Mucusso', 'Luengue'],
            'Cuanza Norte' => ['Cazengo', 'Golungo Alto', 'Cambambe', 'Samba Caju', 'Ambaca', 'Lucala', 'Banga', 'Bolongongo', 'Quiculungo', 'Ngonguembo', 'Massangano', 'Cêrca', 'Tango', 'Terreiro', 'Aldeia Nova', 'Caculo Cabaça', 'Luinga'],
            'Cuanza Sul' => ['Sumbe', 'Calulo', 'Gabela', 'Cassongue', 'Porto Amboím', 'Quibala', 'Seles', 'Waku Kungo', 'Mussende', 'Quilenda', 'Ebo', 'Conda', 'Quirimbo', 'Munenga', 'Quissongo', 'Gungo', 'Sanga', 'Gangula', 'Pambangala', 'Condé', 'Amboiva', 'Lonhe', 'Quenha', 'Boa Entrada'],
            'Cubango' => ['Menongue', 'Cuchi', 'Calai', 'Nancova', 'Cuangar', 'Savate', 'Caiundo', 'Longa', 'Cutato', 'Chinguanja', 'Mavengue'],
            'Cunene' => ['Ombadja', 'Cuanhama', 'Curoca', 'Cahama', 'Cuvelai', 'Namacunde', 'Chiéde', 'Nehone', 'Humbe', 'Mupa', 'Naulila', 'Chitado', 'Cafima', 'Chissuata'],
            'Huambo' => ['Bailundo', 'Huambo', 'Londuimbali', 'Caála', 'Chicala Choloanga', 'Cachiungo', 'Mungo', 'Longonjo', 'Ucuma', 'Ecunha', 'Chinjenje', 'Bimbe', 'Sambo', 'Galanga', 'Alto Hama', 'Chilata', 'Cuima'],
            'Huíla' => ['Caconda', 'Gambos', 'Humpata', 'Lubango', 'Cuvango', 'Quipungo', 'Chibia', 'Quilengues', 'Caluquembe', 'Matala', 'Jamba Mineira', 'Chipindo', 'Chicomba', 'Cacula', 'Dongo', 'Hoque', 'Capelongo', 'Chituto', 'Capunda Cavilongo', 'Viti Vivali', 'Galangue', 'Palanca', 'Chicungo'],
            'Icolo e Bengo' => ['Catete', 'Quiçama', 'Calumbo', 'Cabiri', 'Cabo Ledo', 'Bom Jesus', 'Sequele'],
            'Luanda' => ['Ingombota', 'Cacuaco', 'Cazenga', 'Viana', 'Belas', 'Kilamba Kiaxi', 'Talatona', 'Mussulo', 'Sambizanga', 'Rangel', 'Maianga', 'Samba', 'Camama', 'Mulenvos', 'Kilamba', 'Hoji ya Henda'],
            'Lunda Norte' => ['Cuilo', 'Dundo', 'Lubalo', 'Capenda Camulemba', 'Cuango', 'Lucapa', 'Cambulo', 'Xá Muteba', 'Caungula', 'Lóvua', 'Chitato', 'Xá Cassau', 'Camaxilo', 'Luangue', 'Luremo', 'Canzar', 'Cassanje Calucala', 'Mussungue', 'Cafunfo'],
            'Lunda Sul' => ['Saurimo', 'Muconda', 'Cacolo', 'Dala', 'Chiluage', 'Cassai-Sul', 'Xassengue', 'Alto Chicapa', 'Sombo', 'Muriege', 'Luma Cassai', 'Cazage', 'Muangueji', 'Cassengo'],
            'Malanje' => ['Calandula', 'Malanje', 'Cacuso', 'Massango', 'Marimba', 'Quela', 'Quirima', 'Cangandala', 'Cahombo', 'Kunda dya Baze', 'Cambundi Catembo', 'Caculama', 'Kiwaba Nzoji', 'Luquembo', 'Cateco Cangola', 'Mbanji ya Ngola', 'Cuale', 'Pungu a Ndongo', 'Ngola Luiji', 'Quihuhu', 'Xandel', 'Cambo Suinginge', 'Milando', 'Quitapa', 'Capunda', 'Muquixe', 'Quêssua'],
            'Moxico' => ['Luena', 'Cangamba', 'Lumbala Nguimbo', 'Camanongue', 'Léua', 'Lutembo', 'Lucusse', 'Cangumbe', 'Chiúme', 'Alto Cuito', 'Ninda', 'Lutuai'],
            'Moxico Leste' => ['Cazombo', 'Luacano', 'Cameia', 'Luau', 'Nana Candundo', 'Macondo', 'Caianda', 'Lóvua do Zambeze', 'Lago Dilolo'],
            'Namibe' => ['Moçâmedes', 'Tômbwa', 'Bibala', 'Virei', 'Camucuio', 'Lucira', 'Iona', 'Sacomar', 'Cacimbas'],
            'Uíge' => ['Dange Quitexe', 'Bungo', 'Ambuíla', 'Negage', 'Puri', 'Maquela do Zombo', 'Damba', 'Sanza Pombo', 'Bembe', 'Milunga', 'Songo', 'Quimbele', 'Cangola', 'Uíge', 'Mucaba', 'Nova Esperança', 'Sacandica', 'Nsosso', 'Lucunga', 'Quipedro', 'Massau', 'Vista Alegre', 'Alto Zaza'],
            'Zaire' => ['Soyo', 'Mbanza Kongo', 'Nzeto', 'Tomboco', 'Cuimba', 'Nóqui', 'Luvo', 'Lufico', 'Quêlo', 'Serra de Canda', 'Quindeje'],
        ];
    }
}
